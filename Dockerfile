FROM trafex/alpine-nginx-php7:latest
USER root

# COPY --from=composer /usr/bin/composer /usr/bin/composer
# RUN composer install --optimize-autoloader --no-interaction --no-progress

# FROM composer AS composer

RUN apk --no-cache add gcc libc-dev git python3 py3-pip composer ffmpeg mediainfo util-linux busybox-initscripts

RUN pip install streamlink youtube-dl tcd

RUN mkdir -p /var/www/twitchautomator
# COPY app public src templates /var/www/twitchautomator/
# COPY composer.json composer.lock /var/www/twitchautomator/
COPY . /var/www/twitchautomator/

# RUN git clone https://github.com/MrBrax/TwitchAutomator /var/www/twitchautomator/

RUN cd /var/www/twitchautomator/ && composer install

# RUN cd /var/www/twitchautomator/ && npm install # nodejs

# RUN mkdir -p /home/appuser && addgroup --gid 1001 appuser && adduser --uid 1001 --home /home/appuser --ingroup appuser appuser && chown -R appuser:appuser /home/appuser

# cronjobs
# COPY ./config/cron.txt /etc/crontabs/nobody
# RUN chown nobody:nobody /etc/crontabs/nobody && chmod 775 /etc/crontabs/nobody
# RUN echo "* * * * * echo \"Crontab is working - watchdog 1\"" > /etc/crontabs/test

# CMD ["exec", "crond", "-f", "-l", "2"]

# src perms
RUN chown -R nobody:nobody /var/www/twitchautomator && chmod -R 775 /var/www/twitchautomator

COPY ./docker/nginx.conf /etc/nginx/nginx.conf

# RUN pip install --user streamlink youtube-dl tcd

RUN mkdir -p /home/nobody && chown -R nobody:nobody /home/nobody
ENV HOME /home/nobody
ENV TCD_BIN_DIR=/usr/bin
ENV TCD_MEDIAINFO_PATH=/usr/bin/mediainfo

USER nobody
WORKDIR /var/www/twitchautomator

# RUN /usr/bin/crontab /var/www/twitchautomator/config/cron.txt
# CMD ["/var/www/twitchautomator/docker/entry.sh"]
# ENTRYPOINT ["/var/www/twitchautomator/docker/entry.sh"]

# RUN composer install \
#   --optimize-autoloader \
#   --no-interaction \
#   --no-progress
# 
# RUN
