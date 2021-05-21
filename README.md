# CiviCRM DonutApp Integration

[![CircleCI](https://circleci.com/gh/greenpeace-cee/at.greenpeace.donutapp.svg?style=svg)](https://circleci.com/gh/greenpeace-cee/at.greenpeace.donutapp)

This extension connects CiviCRM with [DonutApp](https://donutapp.io/app/) by [Formunauts](https://www.formunauts.at/en/).

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.2+
* CiviCRM (5.24+)

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl at.greenpeace.donutapp@https://github.com/greenpeace-cee/at.greenpeace.donutapp/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/greenpeace-cee/at.greenpeace.donutapp.git
cv en donutapp
```

## Usage

To use the DonutApp API, you need `client_id` and `client_secret`, which will
be provided by Formunauts.

To import petitions, use the `DonutPetition.import` API call.

To import donations, use the `DonutDonation.import` API call.

Campaigns used for created entities can be specified either via the `import` API
or an `external_campaign_id` field within the DonutApp API response.

## Known Issues

* Only recurring donations are supported
