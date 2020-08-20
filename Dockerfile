FROM pmmp/pocketmine-mp:latest

USER root
RUN apt-get update && apt-get install -y --no-install-recommends git
RUN wget -qO - https://getcomposer.org/installer | php
RUN mv composer.phar /usr/bin/composer
RUN wget -qO /usr/bin/phpstan https://github.com/phpstan/phpstan/releases/download/0.12.38/phpstan.phar
RUN chmod o+x /usr/bin/phpstan
ADD entry.php /usr/bin/entry
ADD default.phpstan.neon /pocketmine/default.phpstan.neon
RUN mkdir /deps
RUN mkdir /source
RUN chown 1000:1000 /pocketmine/default.phpstan.neon /deps /source -R

USER pocketmine
WORKDIR /source

ENV PLUGIN_PATH /
ENV PHPSTAN_CONFIG /pocketmine/default.phpstan.neon
ENTRYPOINT ["entry"]
CMD []
