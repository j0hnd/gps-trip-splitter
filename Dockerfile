FROM php:8.2-cli-alpine

WORKDIR /app

# Install common base packages for HTTPS and timezones
RUN apk add --no-cache tzdata ca-certificates && update-ca-certificates

# Copy your PHP script into the image (optional, since volume mount also used)
COPY process_gps_points.php /app/process_gps_points.php
