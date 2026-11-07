#!/usr/bin/env bash
# Installs the exact CI ZIP into a disposable Joomla instance and loads gallery fixtures.
set -euo pipefail
cd "$(dirname "$0")/../.."

PORT="${CI_JOOMLA_PORT:-8280}"
TIMEOUT="${CI_JOOMLA_TIMEOUT:-300}"
BASE_URL="http://127.0.0.1:${PORT}"
COMPOSE=(docker compose -f tests/Integration/compose.yml)
J() { "${COMPOSE[@]}" exec -T joomla sh -c "$1"; }

VERSION="$(php -r '$xml = simplexml_load_file("dinkygallery.xml"); if (!$xml) { exit(1); } echo (string) $xml->version;')"
PACKAGE=".releases/plg_content_dinkygallery-${VERSION}.zip"
[[ -f "$PACKAGE" ]] || { echo "Expected package ZIP: $PACKAGE" >&2; exit 1; }
PACKAGE_NAME="$(basename "$PACKAGE")"

"${COMPOSE[@]}" up -d
echo "Waiting for Joomla (up to ${TIMEOUT}s)..."
deadline=$((SECONDS + TIMEOUT))
until J '[ -f /var/www/html/installation/joomla.php ]' 2>/dev/null && curl -fsS "$BASE_URL/" >/dev/null; do
  if (( SECONDS > deadline )); then
    echo "Joomla did not start in time." >&2
    "${COMPOSE[@]}" logs --tail=80 joomla >&2 || true
    exit 1
  fi
  sleep 2
done

echo "Installing Joomla headlessly..."
J 'cd /var/www/html && php installation/joomla.php install \
  --site-name="DinkyGallery CI" \
  --admin-user="Admin User" --admin-username=admin --admin-password="admin1234secure" --admin-email=admin@example.com \
  --db-type=mysqli --db-host=db --db-user=joomla --db-pass=joomlapw --db-name=joomla --db-prefix=jos_ -n'
J 'chown -R www-data:www-data /var/www/html/cache /var/www/html/administrator/cache /var/www/html/tmp /var/www/html/administrator/logs; chown www-data:www-data /var/www/html/configuration.php'

echo "Generating deterministic test images..."
"${COMPOSE[@]}" exec -T joomla sh -c 'cd /var/www/html && php -d memory_limit=512M' <<'PHP'
function makeImage($path, $width, $height) {
  if (is_file($path)) return;
  @mkdir(dirname($path), 0755, true);
  $image = imagecreatetruecolor($width, $height);
  imagefill($image, 0, 0, imagecolorallocate($image, 80, 120, 160));
  $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  if ($extension === "png") imagepng($image, $path); else if ($extension === "webp") imagewebp($image, $path, 85); else imagejpeg($image, $path, 85);
  imagedestroy($image);
}
foreach ([["gallery-01/01-landscape.jpg",1600,1000],["gallery-02/01-landscape.jpg",1600,1000],["gallery-02/02-portrait.jpg",1000,1500]] as [$f,$w,$h]) makeImage("images/$f",$w,$h);
for ($i=1;$i<=5;$i++) makeImage(sprintf("images/gallery-05/%02d-shot.jpg",$i),1600,1000);
for ($i=1;$i<=6;$i++) makeImage(sprintf("images/gallery-portrait/%02d-portrait.jpg",$i),1000,1500);
$specs = [["01-landscape.jpg",1600,1000],["02-portrait.jpg",1000,1500],["03-square.png",1200,1200],["04-tiny.png",120,90],["05-huge.jpg",6000,4000],["06-wide.jpg",2400,900],["07-tall.jpg",900,2400],["08-mid.webp",1400,1050],["09-mid.jpg",1500,1000],["10-mid.png",1000,1000],["11-mid.jpg",1280,720],["12-mid.jpg",1024,768]];
foreach ($specs as [$f,$w,$h]) makeImage("images/gallery-12/$f",$w,$h);
file_put_contents("images/gallery-12/notes.txt", "not an image");
PHP
J 'chown -R www-data:www-data /var/www/html/images'

echo "Installing the extension ZIP..."
container_id="$("${COMPOSE[@]}" ps -q joomla)"
docker cp "$PACKAGE" "${container_id}:/tmp/${PACKAGE_NAME}"
J "cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/${PACKAGE_NAME} -n"
J 'rm -f /var/www/html/administrator/cache/autoload_psr4.php'

echo "Loading article fixtures..."
"${COMPOSE[@]}" exec -T db sh -c 'mariadb -ujoomla -pjoomlapw joomla' < .docker/fixtures.sql
J 'cd /var/www/html && php cli/joomla.php cache:clean all -n >/dev/null 2>&1 || true'
echo "Joomla ${JOOMLA_TAG:-5} is ready for DinkyGallery integration checks."
