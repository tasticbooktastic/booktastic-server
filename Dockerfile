FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive \
	  TZ='UTZ' \
	  NOTVISIBLE="in users profile" \
	  STANDALONE=TRUE \
	  SQLHOST=percona \
	  SQLPORT=33060 \
	  SQLUSER=root \
	  SQLPASSWORD=iznik \
	  SQLDB=iznik \
	  PGSQLHOST=postgres \
	  PGSQLUSER=root \
	  PGSQLPASSWORD=iznik \
	  PGSQLDB=iznik \
	  LOVE_JUNK_API=https://staging-elmer.api-lovejunk.com/elmer/v1/freegle-drafts \
	  LOVE_JUNK_SECRET=secret \

# Packages
RUN apt-get update && apt-get install -y dnsutils openssl zip unzip git libxml2-dev libzip-dev zlib1g-dev libcurl4-openssl-dev \
    iputils-ping default-mysql-client vim libpng-dev libgmp-dev libjpeg-turbo8-dev php-xmlrpc php8.1-intl \
    php8.1-xdebug php8.1-mbstring php8.1-simplexml php8.1-curl php8.1-zip postgresql-client php8.1-gd  \
    php8.1-xmlrpc php8.1-redis php8.1-pgsql curl libpq-dev php-pear php-dev libgeoip-dev libcurl4-openssl-dev wget \
    php-mbstring php-mailparse geoip-bin geoip-database php8.1-pdo-mysql cron rsyslog net-tools php8.1-fpm nginx

RUN apt-get remove -y apache2* sendmail* mlocate php-ssh2

RUN mkdir -p /var/www \
	&& cd /var/www \
	&& apt-get -o Acquire::Check-Valid-Until=false -o Acquire::Check-Date=false update \
	&& git clone https://github.com/Freegle/iznik-server.git iznik \
  && touch iznik/standalone

WORKDIR /var/www/iznik

# /etc/iznik.conf is where our config goes.
RUN cp install/iznik.conf.php /etc/iznik.conf \
    && echo secret > /etc/iznik_jwt_secret \
    && sed -ie "s/'SQLHOST', '.*'/'SQLHOST', '$SQLHOST:$SQLPORT'/" /etc/iznik.conf \
    && sed -ie "s/'SQLHOSTS_READ', '.*'/'SQLHOST', '$SQLHOST:$SQLPORT'/" /etc/iznik.conf \
    && sed -ie "s/'SQLHOSTS_MOD', '.*'/'SQLHOST', '$SQLHOST:$SQLPORT'/" /etc/iznik.conf \
    && sed -ie "s/'SQLUSER', '.*'/'SQLUSER', '$SQLUSER'/" /etc/iznik.conf \
    && sed -ie "s/'SQLPASSWORD', '.*'/'SQLPASSWORD', '$SQLPASSWORD'/" /etc/iznik.conf \
    && sed -ie "s/'SQLDB', '.*'/'PGSQLDB', '$PGSQLDB'/" /etc/iznik.conf \
    && sed -ie "s/'PGSQLHOST', '.*'/'PGSQLHOST', '$PGSQLHOST'/" /etc/iznik.conf \
    && sed -ie "s/'PGSQLUSER', '.*'/'PGSQLUSER', '$PGSQLUSER'/" /etc/iznik.conf \
    && sed -ie "s/'PGSQLPASSWORD', '.*'/'PGSQLPASSWORD', '$PGSQLPASSWORD'/" /etc/iznik.conf \
    && sed -ie "s/'PGSQLDB', '.*'/'PGSQLDB', '$PGSQLDB'/" /etc/iznik.conf \
    && sed -ie "s/'LOVE_JUNK_API', '.*'/'LOVE_JUNK_API', '$LOVE_JUNK_API'/" /etc/iznik.conf \
    && sed -ie "s/'LOVE_JUNK_SECRET', '.*'/'LOVE_JUNK_SECRET', '$LOVE_JUNK_SECRET'/" /etc/iznik.conf \
    && echo "[mysql]" > ~/.my.cnf \
    && echo "host=$SQLHOST" >> ~/.my.cnf \
    && echo "user=$SQLUSER" >> ~/.my.cnf \
    && echo "password=$SQLPASSWORD" >> ~/.my.cnf

# Install composer dependencies
RUN wget https://getcomposer.org/composer-2.phar -O composer.phar \
    && cd composer \
    && echo Y | php ../composer.phar install \
    && cd ..

# Cron jobs for background scripts
RUN cat install/crontab | crontab -u root -

# Tidy image
RUN rm -rf /var/lib/apt/lists/*

CMD /etc/init.d/nginx start \
	&& /etc/init.d/cron start \

  # Set up the environment we need. Putting this here means it gets reset each time we start the container.
	#
	# We need to make some minor schema tweaks otherwise the schema fails to install.
  && sed -ie 's/ROW_FORMAT=DYNAMIC//g' install/schema.sql \
  && sed -ie 's/timestamp(3)/timestamp/g' install/schema.sql \
  && sed -ie 's/timestamp(6)/timestamp/g' install/schema.sql \
  && sed -ie 's/CURRENT_TIMESTAMP(3)/CURRENT_TIMESTAMP/g' install/schema.sql \
  && sed -ie 's/CURRENT_TIMESTAMP(6)/CURRENT_TIMESTAMP/g' install/schema.sql \
  && sleep infinity \
	&& mysql -u root -e 'CREATE DATABASE IF NOT EXISTS iznik;' \
  && mysql -u root iznik < install/schema.sql \
  && mysql -u root iznik < install/functions.sql \
  && mysql -u root iznik < install/damlevlim.sql \
  && mysql -u root -e "SET GLOBAL sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'" \
  && php install/testenv.php \

  # Keep the container alive
	&& sleep infinity

EXPOSE 80
