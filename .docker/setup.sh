#!/usr/bin/env bash
# Provision (or repair) the local DinkyGallery test stack. Idempotent.
set -euo pipefail
cd "$(dirname "$0")"

COMPOSE="docker compose -f docker-compose.yml"
J() { $COMPOSE exec -T joomla sh -c "$1"; }

echo "==> docker compose up -d"
$COMPOSE up -d

echo "==> waiting for Joomla core files in the html volume"
for _ in $(seq 1 60); do
  if J '[ -f /var/www/html/libraries/src/Version.php ]' 2>/dev/null; then break; fi
  sleep 2
done

if J '[ -s /var/www/html/configuration.php ]' 2>/dev/null; then
  echo "==> Joomla already installed"
else
  echo "==> installing Joomla (headless)"
  J 'cd /var/www/html && php installation/joomla.php install \
       --site-name="Dinky Gallery Test Site" \
       --admin-user="Admin User" --admin-username=admin --admin-password="admin1234secure" --admin-email=admin@example.com \
       --db-type=mysqli --db-host=db --db-user=joomla --db-pass=joomlapw --db-name=joomla --db-prefix=jos_ \
       -n'
fi

echo "==> symlinking plugin source from /repo"
J '
  set -e
  cd /var/www/html/plugins/content
  rm -rf dinkygallery && mkdir dinkygallery
  ln -s /repo/dinkygallery.xml dinkygallery/dinkygallery.xml
  ln -s /repo/src              dinkygallery/src
  ln -s /repo/services         dinkygallery/services
  ln -s /repo/language         dinkygallery/language
  chown -h www-data:www-data dinkygallery dinkygallery/dinkygallery.xml dinkygallery/src dinkygallery/services dinkygallery/language
  # Media assets (css/js): symlink once the folder exists in the working tree.
  cd /var/www/html/media
  rm -rf plg_content_dinkygallery
  if [ -d /repo/media/plg_content_dinkygallery ]; then
    ln -s /repo/media/plg_content_dinkygallery plg_content_dinkygallery
    chown -h www-data:www-data plg_content_dinkygallery
  fi
'

if J 'cd /var/www/html && php cli/joomla.php extension:list 2>/dev/null | grep -q plg_content_dinkygallery'; then
  echo "==> plugin already registered"
else
  echo "==> discovering + installing the plugin"
  J '
    set -e
    cd /var/www/html
    php cli/joomla.php extension:discover -n >/dev/null
    eid=$(php cli/joomla.php extension:discover:list -n 2>/dev/null | awk "/plg_content_dinkygallery/ {print \$2}")
    [ -n "$eid" ] || { echo "could not determine discovered extension id" >&2; exit 1; }
    php cli/joomla.php extension:discover:install --eid="$eid" -n
  '
fi

echo "==> generating gallery test image folders (spec section 9 matrix)"
J 'cd /var/www/html && php -d memory_limit=512M -r "
function mk(\$path,\$w,\$h){
  if (is_file(\$path)) return;
  @mkdir(dirname(\$path),0755,true);
  \$im=imagecreatetruecolor(\$w,\$h);
  \$bg=imagecolorallocate(\$im,rand(30,220),rand(30,220),rand(30,220));
  imagefill(\$im,0,0,\$bg);
  \$fg=imagecolorallocate(\$im,255,255,255);
  imagerectangle(\$im,2,2,\$w-3,\$h-3,\$fg);
  imagestring(\$im,5,10,10,basename(\$path).\" {\$w}x{\$h}\",\$fg);
  \$ext=strtolower(pathinfo(\$path,PATHINFO_EXTENSION));
  \$ext===\"png\" ? imagepng(\$im,\$path) : (\$ext===\"webp\" ? imagewebp(\$im,\$path,85) : imagejpeg(\$im,\$path,85));
  imagedestroy(\$im);
}
// one image
mk(\"images/gallery-01/01-landscape.jpg\",1600,1000);
// two images
mk(\"images/gallery-02/01-landscape.jpg\",1600,1000);
mk(\"images/gallery-02/02-portrait.jpg\",1000,1500);
// five landscape (cards / loop testing)
for (\$i=1;\$i<=5;\$i++) mk(sprintf(\"images/gallery-05/%02d-shot.jpg\",\$i),1600,1000);
// six portrait
for (\$i=1;\$i<=6;\$i++) mk(sprintf(\"images/gallery-portrait/%02d-portrait.jpg\",\$i),1000,1500);
// twelve images: landscape, portrait, square, tiny, huge, plus assorted + a non-image
mk(\"images/gallery-12/01-landscape.jpg\",1600,1000);
mk(\"images/gallery-12/02-portrait.jpg\",1000,1500);
mk(\"images/gallery-12/03-square.png\",1200,1200);
mk(\"images/gallery-12/04-tiny.png\",120,90);
mk(\"images/gallery-12/05-huge.jpg\",6000,4000);
mk(\"images/gallery-12/06-wide.jpg\",2400,900);
mk(\"images/gallery-12/07-tall.jpg\",900,2400);
mk(\"images/gallery-12/08-mid.webp\",1400,1050);
mk(\"images/gallery-12/09-mid.jpg\",1500,1000);
mk(\"images/gallery-12/10-mid.png\",1000,1000);
mk(\"images/gallery-12/11-mid.jpg\",1280,720);
mk(\"images/gallery-12/12-mid.jpg\",1024,768);
if (!is_file(\"images/gallery-12/notes.txt\")) file_put_contents(\"images/gallery-12/notes.txt\",\"not an image, must be skipped\");
" && chown -R www-data:www-data images'

echo "==> loading fixtures + enabling/configuring the plugin"
$COMPOSE exec -T db sh -c 'mariadb -ujoomla -pjoomlapw joomla' < fixtures.sql

echo "==> clearing cache"
J 'cd /var/www/html && php cli/joomla.php cache:clean --all >/dev/null 2>&1 || true'

cat <<'EOF'

Ready.

  Site    http://dinkygallery.localhost/
  Admin   http://dinkygallery.localhost/administrator/   (admin / admin1234secure)

  Test page (linked from the Main Menu as "DinkyGallery Test"):
    http://dinkygallery.localhost/dinkygallery-test   (article id 110, eight labelled cases)

  Raw fixture articles — http://dinkygallery.localhost/index.php?option=com_content&view=article&id=N
    id 101  {gallery gallery-12}                                  bare form, 12 images
    id 102  {gallery folder="gallery-02" cards="2" size="80"}     attribute form
    id 103  two galleries in one article (gallery-01 + gallery-12 size=40 middle=close loop=0)
    id 104  {gallery missing-folder} + {gallery ../escape}        both must render nothing
    id 105  {gallery} inside <pre> (must stay raw) + one real gallery

  Image folders: gallery-01 (1), gallery-02 (2), gallery-05 (5), gallery-portrait (6),
                 gallery-12 (12, incl. 120x90, 6000x4000 and a notes.txt to skip)

  Plugin source is symlinked from the working tree: edit src/*.php, media/*.css|js
  and just reload (opcache revalidates within ~2s). After changing dinkygallery.xml:
      docker compose -f .docker/docker-compose.yml exec joomla \
        php /var/www/html/cli/joomla.php extension:discover
  After changing language .ini files:
      docker compose -f .docker/docker-compose.yml exec joomla \
        php /var/www/html/cli/joomla.php cache:clean --all
EOF
