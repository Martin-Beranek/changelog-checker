# Changelog Checker
Composer plugin to check changelog files for breaking changes.
<img width="1119" height="388" alt="image" src="https://github.com/user-attachments/assets/0e3d2162-9795-4846-91e9-3b574f601e9d" />
Displays warnings about all (or selected plugins) breaking changes when updating packages.
Option to write changes to a file (maybe to check in PRs ...). 

## Installation

```bash
composer require tastysoul/changelog-checker --dev
```

composer.json should contain the following configuration to allow the plugin to run:
```json
"config": {
    "allow-plugins": {
        "tastysoul/changelog-checker": true
    }
}
```

## Settings

In your `composer.json`, you can configure which plugins to check for breaking changes:

```json
"extra": {
    "changelog-checker": {
        "plugins": ["vendor/package-a", "vendor/package-b"]
    }
}
```

Empty or missing array means all updated packages are checked.

### Options
```bash
--plugins=vendor/package-a,vendor/package-b
```
Plugins to check for breaking changes. If not specified, all updated packages are checked.
```bash
-a --all-changes
```
Output all active changelog entries, not only [BC] lines

```bash
-w --writeToFile
```
Write output to file

```bash
-v -verbose
```
Output more information about the process

## Usage

```bash
composer update
```

Or with command

```bash
composer changelog:check "your/package:1.0.0"
```
