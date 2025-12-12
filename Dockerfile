FROM php:8.2-fpm

# 安装PHP扩展
RUN docker-php-ext-install pdo_mysql mysqli

# 复制项目文件到www目录
COPY . /var/www/html/

# 设置文件权限
RUN chown -R www-data:www-data /var/www/html/
RUN chmod -R 755 /var/www/html/

# 暴露PHP-FPM端口
EXPOSE 9000
