FROM wordpress:6.9-php8.2-apache

COPY plugin/delegat-bridge /usr/src/delegat-bridge
COPY shop-entrypoint.sh /usr/local/bin/shop-entrypoint.sh

RUN sed -i 's/\r$//' /usr/local/bin/shop-entrypoint.sh \
    && chmod +x /usr/local/bin/shop-entrypoint.sh \
    && mkdir -p /usr/src/wordpress/wp-content/plugins/delegat-bridge \
    && cp -a /usr/src/delegat-bridge/. /usr/src/wordpress/wp-content/plugins/delegat-bridge/

ENTRYPOINT ["shop-entrypoint.sh"]
CMD ["apache2-foreground"]
