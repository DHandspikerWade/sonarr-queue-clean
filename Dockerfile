FROM php:8.1-cli-alpine3.20
COPY cleaner.php /cleaner.php
CMD [ "php", "/cleaner.php"]