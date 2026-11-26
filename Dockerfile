FROM php:8.2-apache

# Install PostgreSQL dev libraries and enable PDO drivers plus cURL for Resend.
RUN apt-get update && apt-get install -y libpq-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_pgsql curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite
RUN printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-enabled/allow-overrides.conf

RUN sed -i 's/80/10000/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

# Forward environment variables from container/Render to PHP getenv().
RUN echo "PassEnv DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD DB_SSLMODE RESEND_API_KEY RESEND_FROM_EMAIL APP_ALLOWED_ORIGIN" >> /etc/apache2/conf-enabled/environment.conf

WORKDIR /var/www/html
COPY . /var/www/html/

EXPOSE 10000
