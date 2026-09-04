#!/usr/bin/env bash
# Provision the Joomla 6 acceptance stack: install Joomla 6, install the plugin
# from the built ZIP, seed the same fixtures + images. Idempotent.
set -euo pipefail
cd "$(dirname "$0")"

COMPOSE="docker compose -f docker-compose.j6.yml"
J() { $COMPOSE exec -T joomla sh -c "$1"; }

ZIP=$(ls -1 ../.releases/plg_content_dinkygallery-*.zip 2>/dev/null | sort -V | tail -1 || true)
[ -n "$ZIP" ] || { echo "No .releases/plg_content_dinkygallery-*.zip — run 'phing package' first." >&2; exit 1; }
echo "==> package: $(basename "$ZIP")"

echo "==> docker compose up -d"
$COMPOSE up -d

echo "==> waiting for Joomla core files"
for _ in $(seq 1 60); do
  if J '[ -f /var/www/html/libraries/src/Version.php ]' 2>/dev/null; then break; fi
  sleep 2
done

if J '[ -s /var/www/html/configuration.php ]' 2>/dev/null; then
  echo "==> Joomla already installed"
else
  echo "==> installing Joomla 6 (headless)"
  J 'cd /var/www/html && php installation/joomla.php install \
       --site-name="Dinky Gallery J6" \
       --admin-user="Admin User" --admin-username=admin --admin-password="admin1234secure" --admin-email=admin@example.com \
       --db-type=mysqli --db-host=db --db-user=joomla --db-pass=joomlapw --db-name=joomla --db-prefix=jos_ \
       -n'
fi

echo "==> installing plugin from the built zip"
# /repo is read-only; the installer needs the archive on a writable path.
J "cp /repo/.releases/$(basename "$ZIP") /tmp/dg.zip && cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/dg.zip -n && rm -f /tmp/dg.zip"

echo "==> generating gallery test image folders"
J 'cd /var/www/html && php -d memory_limit=512M -r "
function mk(\$p,\$w,\$h){ if(is_file(\$p))return; @mkdir(dirname(\$p),0755,true);
 \$im=imagecreatetruecolor(\$w,\$h); imagefill(\$im,0,0,imagecolorallocate(\$im,rand(30,220),rand(30,220),rand(30,220)));
 \$fg=imagecolorallocate(\$im,255,255,255); imagerectangle(\$im,2,2,\$w-3,\$h-3,\$fg); imagestring(\$im,5,10,10,basename(\$p).\" {\$w}x{\$h}\",\$fg);
 \$e=strtolower(pathinfo(\$p,PATHINFO_EXTENSION)); \$e===\"png\"?imagepng(\$im,\$p):(\$e===\"webp\"?imagewebp(\$im,\$p,85):imagejpeg(\$im,\$p,85)); imagedestroy(\$im); }
mk(\"images/gallery-01/01-landscape.jpg\",1600,1000);
mk(\"images/gallery-02/01-landscape.jpg\",1600,1000); mk(\"images/gallery-02/02-portrait.jpg\",1000,1500);
for(\$i=1;\$i<=5;\$i++) mk(sprintf(\"images/gallery-05/%02d-shot.jpg\",\$i),1600,1000);
for(\$i=1;\$i<=6;\$i++) mk(sprintf(\"images/gallery-portrait/%02d-portrait.jpg\",\$i),1000,1500);
mk(\"images/gallery-12/01-landscape.jpg\",1600,1000); mk(\"images/gallery-12/02-portrait.jpg\",1000,1500);
mk(\"images/gallery-12/03-square.png\",1200,1200); mk(\"images/gallery-12/04-tiny.png\",120,90);
mk(\"images/gallery-12/05-huge.jpg\",6000,4000); mk(\"images/gallery-12/06-wide.jpg\",2400,900);
mk(\"images/gallery-12/07-tall.jpg\",900,2400); mk(\"images/gallery-12/08-mid.webp\",1400,1050);
mk(\"images/gallery-12/09-mid.jpg\",1500,1000); mk(\"images/gallery-12/10-mid.png\",1000,1000);
mk(\"images/gallery-12/11-mid.jpg\",1280,720); mk(\"images/gallery-12/12-mid.jpg\",1024,768);
if(!is_file(\"images/gallery-12/notes.txt\")) file_put_contents(\"images/gallery-12/notes.txt\",\"skip me\");
" && chown -R www-data:www-data images'

echo "==> loading fixtures"
$COMPOSE exec -T db sh -c 'mariadb -ujoomla -pjoomlapw joomla' < fixtures.sql

echo "==> clearing cache"
J 'cd /var/www/html && php cli/joomla.php cache:clean --all >/dev/null 2>&1 || true'

cat <<'EOF'

Ready (Joomla 6, zip-installed).

  Site    http://dinkygallery6.localhost/
  Admin   http://dinkygallery6.localhost/administrator/   (admin / admin1234secure)
  Test    http://dinkygallery6.localhost/dinkygallery-test

  Tear down:  docker compose -f docker-compose.j6.yml down -v
EOF
