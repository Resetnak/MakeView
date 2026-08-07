FROM php:8.3-cli-alpine

WORKDIR /app
COPY index.php .

# Parsedown: single-file markdown renderer, no composer needed.
ADD --checksum=sha256:af4a4b29f38b5a00b003a3b7a752282274c969e42dee88e55a427b2b61a2f38f \
    https://raw.githubusercontent.com/erusev/parsedown/1.7.4/Parsedown.php Parsedown.php

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
