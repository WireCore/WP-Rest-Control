FROM wordpress:php8.5-apache

# install some needed packages
RUN apt update && apt install mariadb-client -y

# install wp-cli
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp
