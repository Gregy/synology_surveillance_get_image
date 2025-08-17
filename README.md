# Purpose

You can use this script to include a preview from your cameras on your webpage without exposing your DSM to the public.

# Local testing

docker run -it --rm --network=host -v "$(pwd):/var/www" php:7.3 bash
  php -S localhost:8000
curl 'http://localhost:8000/getImage.php?camera=4&profile=0' -o test.jpg
