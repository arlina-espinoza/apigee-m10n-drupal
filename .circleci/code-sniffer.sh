#!/bin/bash -ex

# Runs CodeSniffer checks on a Drupal module.

if [ ! -f dependencies_updated ]
then
  ./update-dependencies.sh $1
fi

# Install dependencies and configure phpcs
vendor/bin/phpcs --config-set installed_paths vendor/drupal/coder/coder_sniffer

vendor/bin/phpmd modules/$1/src html cleancode,codesize,design,unusedcode --ignore-violations-on-exit --reportfile /tmp/artifacts/phpmd/index.html
vendor/bin/phpmetrics --extensions=php,inc,module --report-html=/tmp/artifacts/phpmetrics --git modules/$1
# Check coding standards
vendor/bin/phpcs --standard=Drupal --report=junit --report-junit=/tmp/artifacts/phpcs/phpcs.xml modules/$1
