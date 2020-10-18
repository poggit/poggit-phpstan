FROM ubuntu:18.04 as phpBuild

USER root

RUN mkdir /php
RUN apt-get update && apt-get install --no-install-recommends -y ca-certificates wget make autoconf automake libtool-bin m4 gzip bzip2 bison g++ git cmake pkg-config re2c
WORKDIR /php
RUN wget -q https://raw.githubusercontent.com/pmmp/php-build-scripts/stable/compile.sh
RUN bash compile.sh -t linux64 -f -u -g -l -j

# New slate to lose all unwanted libs (~300mb lost here)
FROM ubuntu:18.04

RUN apt-get update && apt-get install --no-install-recommends -y ca-certificates wget

COPY --from=phpBuild /php/bin/php7 /usr/php
RUN grep -q '^extension_dir' /usr/php/bin/php.ini && \
	sed -ibak "s{^extension_dir=.*{extension_dir=\"$(find /usr/php -name *debug-zts*)\"{" /usr/php/bin/php.ini || echo "extension_dir=\"$(find /usr/php -name *debug-zts*)\"" >> /usr/php/bin/php.ini
RUN ln -s /usr/php/bin/php /usr/bin/php

RUN wget -qO - https://getcomposer.org/installer | php
RUN mv composer.phar /usr/bin/composer

RUN mkdir /deps
RUN mkdir /source

# Default files:
ADD entry.php /usr/bin/entry
ADD default.phpstan.neon /source/default.phpstan.neon

# Permissions:
RUN groupadd -g 1000 client
RUN useradd -r -m -u 1000 -g client client

RUN chown 1000:1000 /deps /source -R

USER client
WORKDIR /source

ENV PLUGIN_PATH /
ENV DEFAULT_PHPSTAN_CONFIG /source/default.phpstan.neon

ENTRYPOINT ["entry"]
CMD []
