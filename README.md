# Aerones task

## Requirements

Docker Compose v2.10+

## Usage

To build and spin up the Docker containers, run the following command:
```
docker compose build --no-cache
docker compose up --pull always -d --wait
```

To execute command that downloads the files:
```
docker compose exec -T php bin/console app:download
```

This will execute the download command in the context of the PHP container. The directory paths reported in the output are relative to the PHP container. The downloaded files will be stored in the `var/storage` directory. While the in progress files will be stored in temporary directory accessible only from the PHP container.

Once you are done with the application, you can stop the Docker containers by running:
```
docker compose down --remove-orphans
```

> [!TIP]
> For convenience, Docker setup has been provided to run this test task. However, you can run the application without Docker as well if you have PHP 8.2+ and Composer installed. In that case follow typical Symfony framework installation steps, where you only need to run only CLI command.
