FROM php:8.3-cli
COPY cleaner.php /cleaner.php
CMD [ "php", "/cleaner.php"]