# GLPI with the Surge plugin preinstalled (for trying Surge out).
#   docker build --build-arg GLPI_VERSION=11.0 -t glpi-surge .
# Based on the official image (https://github.com/glpi-project/docker-images); see docker/docker-compose.yml.
ARG GLPI_VERSION=11.0
FROM glpi/glpi:${GLPI_VERSION}

LABEL org.opencontainers.image.title="GLPI + Surge" \
      org.opencontainers.image.description="GLPI with the Surge plugin (major incident detection) preinstalled" \
      org.opencontainers.image.source="https://github.com/CodeByPinar/glpi-surge" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.authors="Pınar Topuz"

COPY --chown=www-data:www-data . /var/www/glpi/plugins/surge
