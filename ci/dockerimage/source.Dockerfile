# description:
#   The fist part of the source image installs all dependencies specified in the tine20 repo. But it should not change,
#   if only the tine20 code changes. E.g. when the composer.lock or npm-shrinkwrap.json changes this image part can change.
#
#   This second part adds the tine20 source. And executes dependency scripts, which need the tine20 source.
#
# build:
#   $ docker build [...] --build-arg='BASE_IMAGE=base-tag' .
#
# ARGS:
#   BASE_IMAGE=base
#   SOURCE_ICON_SET_PROVIDER=source-icon-set-provider can not be skipt, but can be run with prebuild image
#   TINE20ROOT=/usr/share
#   PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
#   NPM_INSTALL_COMMAND="npm --no-optional install" - used set npm proxy in the ci
#   NODE_TLS_REJECT_UNAUTHORIZED=1 - needed to use the npm proxy

ARG BASE_IMAGE=base

#  -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -
FROM ${BASE_IMAGE} as cache-invalidator
ARG ALPINE_PHP_REPOSITORY_BRANCH=v3.12
ARG CACHE_BUST=0
RUN apk add --no-cache --simulate git npm | sha256sum >> /cachehash
RUN if [ ${ALPINE_PHP_PACKAGE} != "php8" ]; then \
        apk add --no-cache --simulate --repository http://nl.alpinelinux.org/alpine/${ALPINE_PHP_REPOSITORY_BRANCH}/community \
        composer | sha256sum >> /cachehash; \
    fi

#  -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -
# .git changes with every commit and broke cachin form source downwards. So it needs to be excluded and only the result is used
FROM ${BASE_IMAGE} as icon-set-provider
ARG TINE20ROOT=/usr/share
COPY .git ${TINE20ROOT}/.git
RUN apk add --no-cache git
RUN git submodule update --init

#  -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -
FROM ${BASE_IMAGE} as source
ARG PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ARG NPM_INSTALL_COMMAND="npm --no-optional install"
ARG NODE_TLS_REJECT_UNAUTHORIZED=1
ARG TINE20ROOT=/usr/share
ARG ALPINE_PHP_REPOSITORY_BRANCH=v3.12
ARG ALPINE_PHP_PACKAGE=php7

COPY --from=cache-invalidator /cachehash /usr/local/lib/container/
RUN apk add --no-cache git npm

RUN if [ ${ALPINE_PHP_PACKAGE} == "php8" ]; then \
        php -r "copy('https://getcomposer.org/installer', '/composer-setup.php');"; \
        php -r "if (hash_file('sha384', '/composer-setup.php') === '55ce33d7678c5a611085589f1f3ddf8b3c52d662cd01d4ba75c0ee0459970c2200a51f492d557530c71c15d8dba01eae') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('/composer-setup.php'); } echo PHP_EOL;"; \
        php /composer-setup.php; \
        php -r "unlink('/composer-setup.php');"; \
        ln -s /usr/share/composer.phar /usr/bin/composer; \
    else \
        apk add --no-cache --repository http://nl.alpinelinux.org/alpine/${ALPINE_PHP_REPOSITORY_BRANCH}/community composer; \
    fi

# used to inject http auth credentials for git repos
COPY ci/dockerimage/utility/.gitconfig /root/.gitconfig

RUN mkdir -p ${TINE20ROOT}/tine20/Tinebase/js
RUN mkdir ${TINE20ROOT}/scripts

COPY tine20/library ${TINE20ROOT}/tine20/library
COPY tine20/composer.json ${TINE20ROOT}/tine20/composer.json
COPY tine20/composer.lock ${TINE20ROOT}/tine20/composer.lock
COPY tine20/Tinebase/js/package.json ${TINE20ROOT}/tine20/Tinebase/js/package.json
COPY tine20/Tinebase/js/npm-shrinkwrap.json ${TINE20ROOT}/tine20/Tinebase/js/npm-shrinkwrap.json
COPY scripts/packaging/composer/composerLockRewrite.php ${TINE20ROOT}/scripts/packaging/composer/composerLockRewrite.php 


RUN if [ "COMPOSER_LOCK_REWRITE" == "true" ]; then \
        php ${TINE20ROOT}/scripts/packaging/composer/composerLockRewrite.php ${TINE20ROOT}/tine20/composer.lock satis.default.svc.cluster.local; \
    fi
RUN cd ${TINE20ROOT}/tine20 && composer install --no-scripts --no-ansi --no-progress --no-suggest
RUN cd ${TINE20ROOT}/tine20/Tinebase/js && ${NPM_INSTALL_COMMAND}

# first part ^^
# until here everything schould be cachable for a normal tine20 code change. Only if composer or npm packets are change
# the cache schould be rebuild. Becouse of this we need to copy composer.json and co first and can now overwrite them
# with the tine20 folder
# second part vv

COPY tine20 ${TINE20ROOT}/tine20/
COPY tests ${TINE20ROOT}/tests/
COPY scripts ${TINE20ROOT}/scripts/

RUN if [ "COMPOSER_LOCK_REWRITE" == "true" ]; then \
        php ${TINE20ROOT}/scripts/packaging/composer/composerLockRewrite.php ${TINE20ROOT}/tine20/composer.lock satis.default.svc.cluster.local; \
    fi
RUN cd ${TINE20ROOT}/tine20 && composer install --no-ansi --no-progress --no-suggest --no-scripts

COPY --from=icon-set-provider ${TINE20ROOT}/tine20/images/icon-set/ ${TINE20ROOT}/tine20/images/icon-set
