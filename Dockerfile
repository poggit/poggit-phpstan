FROM pmmp/pocketmine-mp:latest

USER root
RUN apt-get update && apt-get install -y --no-install-recommends git
RUN wget -qO - https://getcomposer.org/installer | php
RUN mv composer.phar /usr/bin/composer
RUN wget -qO /usr/bin/phpstan https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar
RUN chmod o+x /usr/bin/phpstan
ADD entry.php /usr/bin/entry
ADD default.phpstan.neon /pocketmine/default.phpstan.neon
ADD root-entry.sh /usr/bin/root-entry.sh
RUN mkdir /deps
RUN mkdir /source
RUN chown 1000:1000 /pocketmine/default.phpstan.neon /deps /source -R

WORKDIR /source

ENV PLUGIN_PATH /
ENV PHPSTAN_CONFIG /pocketmine/default.phpstan.neon

ENTRYPOINT ["timeout", "600", "/usr/bin/root-entry.sh"]
CMD []
