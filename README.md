# quickStatAdminParticipationAndStat

A plugin for limesurvey to show some quick statistics about participation and satisfaction.

## Installation

See [Install and activate a plugin for LimeSurvey](https://extensions.sondages.pro/install-and-activate-a-plugin-for-limesurvey) for details.

This version is for LimeSurvey 4 and up and was tested on LimeSurvey 5 and LimeSurvey 6. It can work on LimeSurvey 7, 8 and 9.

### Via GIT
- Go to your LimeSurvey Directory
- Clone in plugins/adminStats directory : `git clone https://gitlab.com/SondagesPro/ExportAndStats/quickStatAdminParticipationAndStat.git quickStatAdminParticipationAndStat`

### Via ZIP dowload
- Download <http://extensions.sondages.pro/IMG/auto/quickStatAdminParticipationAndStat.zip>
- Extract : `unzip quickStatAdminParticipationAndStat.zip`
- Move the directory to  plugins/ directory inside LimeSUrvey

## Usage

Global settings offer what survey admin can choose for particpation.

In Survey settings you can choose the question to show for participation and question for satisfaction.

- Participation question are single choice questions or token attribute (if exist). 
- Satisfaction question can be single choice, numeric or array question type. The mean is calculated with numerical code of answers only, this allow to use Not Applicable code NA for example.

If the an user don't have global permission except login : it was redirected to a survey list. Only survey with statictics Permission are shown.

## Contribution and issue

All contribution are welcome. It's better to use [gitlab](https://gitlab.com/SondagesPro/ExportAndStats/quickStatAdminParticipationAndStat) for all contributions and feedback. Github should only be used excetionally.

No support is done on issues, for professional support, please use our [contact form](https://extensions.sondages.pro/contact) or our [support website](https://support.sondages.pro/).

## Home page & Copyright
- HomePage [extensions.sondages.pro](https://extensions.sondages.pro/export-statistics-and-database/quick-statistics-panel-participation-and-satisfaction/)
- Copyright © 2016-2023 Denis Chenu <http://sondages.pro>
- Copyright © 2016-2023 Advantage <http://www.advantage.fr>
- Licence : GNU Affero General Public License <https://www.gnu.org/licenses/agpl-3.0.html>

## Acknowledgements

If you find this project useful, you can support its development through [Donate](https://support.sondages.pro/open.php?topicId=12), [Liberapay](https://liberapay.com/SondagesPro/), or [OpenCollective](https://opencollective.com/sondagespro). Thank you!

Code quality and security are continuously analyzed by [SonarCloud](https://sonarcloud.io/) [CodeRabbit](https://coderabbit.ai/), [Snyk](https://snyk.io/), and [Semgrep](https://semgrep.dev/).

Thanks to GitLab for providing the hosting platform, and to SonarCloud, CodeRabbit, Snyk, and Semgrep for making their services available to open source projects.
