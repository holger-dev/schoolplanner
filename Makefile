APP_NAME := schoolplanner
BUILD_DIR := build
APPSTORE_BUILD_DIR := $(BUILD_DIR)/artifacts/appstore
APPSTORE_PACKAGE_DIR := $(APPSTORE_BUILD_DIR)/$(APP_NAME)
APPSTORE_PACKAGE := $(APPSTORE_BUILD_DIR)/$(APP_NAME).tar.gz
CERT_DIR := $(HOME)/.nextcloud/certificates

RSYNC_EXCLUDES := \
	--exclude='.git/' \
	--exclude='.github/' \
	--exclude='docker/' \
	--exclude='images/' \
	--exclude='node_modules/' \
	--exclude='src/' \
	--exclude='build/' \
	--exclude='.nextcloud/' \
	--exclude='.idea/' \
	--exclude='.vscode/' \
	--exclude='.DS_Store' \
	--exclude='compose.yaml' \
	--exclude='package-lock.json' \
	--exclude='package.json' \
	--exclude='webpack.js' \
	--exclude='composer.lock' \
	--exclude='composer.json'

.PHONY: all clean deps build appstore

all: deps build

clean:
	rm -rf $(BUILD_DIR)
	rm -f appinfo/signature.json

deps:
	composer install --no-dev --prefer-dist --no-interaction
	npm ci

build:
	npm run build

appstore: clean
	mkdir -p $(APPSTORE_BUILD_DIR)
	rsync -a $(RSYNC_EXCLUDES) ./ $(APPSTORE_PACKAGE_DIR)/
	mkdir -p $(CERT_DIR)
	php ./bin/tools/file_from_env.php app_private_key "$(CERT_DIR)/$(APP_NAME).key"
	php ./bin/tools/file_from_env.php app_public_crt "$(CERT_DIR)/$(APP_NAME).crt"
	@if [ -f "$(CERT_DIR)/$(APP_NAME).key" ] && [ -f "$(CERT_DIR)/$(APP_NAME).crt" ]; then \
		echo "Signing app files..."; \
		php ../../occ integrity:sign-app \
			--privateKey="$(CERT_DIR)/$(APP_NAME).key" \
			--certificate="$(CERT_DIR)/$(APP_NAME).crt" \
			--path="$(APPSTORE_PACKAGE_DIR)"; \
		echo "Signing app files... done"; \
	else \
		echo "No signing certificate configured, creating unsigned archive."; \
	fi
	tar -czf "$(APPSTORE_PACKAGE)" -C "$(APPSTORE_BUILD_DIR)" "$(APP_NAME)"
