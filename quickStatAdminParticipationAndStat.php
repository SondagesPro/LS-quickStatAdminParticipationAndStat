<?php

/**
 * Shown quick stat to allowed admin user
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2016-2026 Denis Chenu <https://www.sondages.pro>
 * @copyright 2016-2025 Advantage <http://www.advantage.fr>
 * @copyright 2025-2026 PAQS <http://www.paqs.be>
 * @license AGPL v3
 * @version 5.7.2
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 */

class quickStatAdminParticipationAndStat extends PluginBase
{
    protected $storage = "DbStorage";
    protected static $description = "Show some specific statitics to your admin user.";
    protected static $name = "quickStatAdminParticipationAndStat";

    /** @inheritdoc, this plugin allow this public method */
    public $allowedPublicMethods = array(
        'actionSettings',
        'actionSaveSettings',
    );

    /**
     * @var string[] : this answer (label) must be moved at end
     * @todo : move this to settings
     */
    private $aPushTokenValue = [
        "Autre",
        "Autres",
        "autre",
        "autres",
        "Other",
        "Others",
        "other",
        "other",
    ];

    /**
     * @var integer : survey ID
     */
    private $iSurveyId;

    /**
     * @var array : render Data
     */
    private $aRenderData = [];

    /**
     * @var string : language for survey
     */
    private $surveyLanguage;

    protected $settings = [
        "docu" => ["type" => "info", "content" => ""],
        "redirectAfterLogin" => [
            "type" => "boolean",
            "label" => "Check if user have only statistics permission after login and redirect to this plugin if yes.",
            "help" => "Redirection happen if it's not already a plugin in current redirection.",
            "default" => 1,
        ],
        "dailyRateEnterAllow" => [
            "type" => "checkbox",
            "label" => "Activate daily participation by default",
            "default" => 1,
        ],
        "dailyRateActionAllow" => [
            "type" => "checkbox",
            "label" => "Activate daily action by default",
            "default" => 0,
        ],
    ];

    /** private boolean isOwnRedit*/
    private $isCurrentUrl = false;

    /** @inheritdoc **/
    public function init()
    {
        if (version_compare(App()->getConfig("versionnumber"), "4", "<")) {
            return;
        }
        $this->subscribe('beforeToolsMenuRender');

        $this->subscribe('afterSuccessfulLogin');
        /* Survey settings */
        $this->subscribe("beforeSurveySettings");
        /* Show page */
        $this->subscribe("newDirectRequest");
        /* Broken register
        $this->subscribe("getValidScreenFiles");
        */
    }

    /**
     * Add the link to setçings in plugin settings
     */
    public function beforeSurveySettings()
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        /* @Todo move this to own page */
        $surveyId = $this->getEvent()->get('survey');
        $settings = [];
        if (Permission::model()->hasSurveyPermission($surveyId, 'statistics', 'read')) {
            $accesUrl = App()->createUrl("plugins/direct", [
                "plugin" => $this->getName(),
                "function" => "stat",
                "sid" => $surveyId,
            ]);
            if (self::isSurveyActive($surveyId)) {
                $settings["statlink"] = [
                    "type" => "info",
                    'content' => "<div class='d-grid'>" . CHtml::link($this->translate("Link to statitics"), $accesUrl, array("target" => '_blank','class' => 'btn btn-block btn-default btn-outline-secondary btn-lg')) . "</div>",
                ];
            }
        }
        if (Permission::model()->hasSurveyPermission($surveyId, 'surveysettings', 'read')) {
            $managementUrl = Yii::app()->createUrl(
                'admin/pluginhelper',
                array(
                    'sa' => 'sidebody',
                    'plugin' => get_class($this),
                    'method' => 'actionSettings',
                    'surveyId' => $surveyId
                )
            );
            $settings["management"] = [
                "type" => "info",
                'content' => "<div class='d-grid'>" . CHtml::link($this->translate("Manage statistics"), $managementUrl, array('class' => 'btn btn-block btn-default btn-outline-secondary btn-lg')) . "</div>",
            ];
        }
        if (empty($settings)) {
            return;
        }
        $this->getEvent()->set("surveysettings.{$this->id}", array(
            'name' => get_class($this),
            'settings' => $settings
        ));
    }

    /**
     * see beforeToolsMenuRender event
     * @deprecated ? See https://bugs.limesurvey.org/view.php?id=15476
     * @return void
     */
    public function beforeToolsMenuRender()
    {
        $event = $this->getEvent();
        $surveyId = $event->get('surveyId');
        if (!Permission::model()->hasSurveyPermission($surveyId, 'surveysettings', 'read')) {
            return;
        }
        if (!Permission::model()->hasSurveyPermission($surveyId, 'surveycontent', 'read')) {
            return;
        }
        $aMenuItem = array(
            'label' => $this->translate('Quick statistics'),
            'iconClass' => 'fa fa-bar-chart',
            'href' => Yii::app()->createUrl(
                'admin/pluginhelper',
                array(
                    'sa' => 'sidebody',
                    'plugin' => get_class($this),
                    'method' => 'actionSettings',
                    'surveyId' => $surveyId
                )
            ),
        );
        $menuItem = new \LimeSurvey\Menu\MenuItem($aMenuItem);
        $event->append('menuItems', array($menuItem));
    }

    /** The settings on own page */
    public function actionSettings($surveyId)
    {
        $oSurvey = Survey::model()->findByPk($surveyId);
        if (!$oSurvey) {
            throw new CHttpException(404, gT("This survey does not seem to exist."));
        }
        if (!Permission::model()->hasSurveyPermission($oSurvey->sid, 'surveysettings', 'read')) {
            throw new CHttpException(403);
        }
        if (!Permission::model()->hasSurveyPermission($oSurvey->sid, 'surveycontent', 'read')) {
            throw new CHttpException(403);
        }
        /* Basic data */
        $aData = array(
            'pluginClass' => get_class($this),
            'surveyId' => $surveyId,
        );
        /* Lang */
        $aData['lang'] = array(
            'Close' => $this->translate("Close"),
            'Quick statistics settings' => $this->translate("Quick statistics settings"),
        );

        $aData['aSettings'] = $this->getSettings($oSurvey->sid);
        $aData['updatepermission'] = Permission::model()->hasSurveyPermission($surveyId, 'surveysettings', 'update');
        $aData['form'] = array(
            'action' => App()->createUrl('admin/pluginhelper/sa/sidebody', array('plugin' => get_class($this),'method' => 'actionSaveSettings','surveyId' => $surveyId)),
            'close' => App()->createUrl('surveyAdministration/view', array('surveyid' => $surveyId))
        );
        return $this->renderPartial('admin.settings', $aData, true);
    }

    /** The settings **/
    private function getSettings($surveyId)
    {
        $aSettings = [];
        $oSurvey = Survey::model()->findByPk($surveyId);
        /* var string language to be used */
        $lang = $oSurvey->language;
        $aSettings = [];
        $url = App()->createUrl("plugins/direct", [
            "plugin" => $this->getName(),
            "function" => "stat",
            "sid" => $surveyId,
        ]);
        if (self::isSurveyActive($surveyId)) {
            $aSettings["statlink"] = [
                "type" => "info",
                "content" =>
                    "<h2>" .
                    $this->translate("Link to statitics :") .
                    "<a href='{$url}'>{$url}</a></h2>",
            ];
        } else {
            $aSettings["statlink"] = [
                "type" => "info",
                "content" =>
                    "<p class='alert alert-info'>" .
                    $this->translate("Survey is not activated : no statistics can be shown.") .
                    "</p>",
            ];
        }
        $aSettings["alternateTitle"] = [
            "type" => "string",
            "label" => $this->translate("Alternate title"),
            "current" => $this->get(
                "alternateTitle",
                "Survey",
                $surveyId,
                ""
            ),
        ];
        $aSettings["numberMax"] = [
            "type" => "int",
            "label" => $this->translate("Expected participation"),
            "help" => $this->translate("Used for participation rate, replace token count value."),
            "htmlOptions" => ["min" => 0],
            "current" => $this->get(
                "numberMax",
                "Survey",
                $surveyId,
                0
            ),
        ];
        $aSettings["step"] = [
            "type" => "int",
            "label" => $this->translate("Step to be used for satisfaction tab"),
            "help" => $this->translate("By default : use only submitted response for statistics, you can choose the last step/page if you want to include not submitted response. Use 0 or -1 to include all response"),
            "htmlOptions" => [
                "min" => -1,
                "placeholder" => $this->translate("Completed response")
            ],
            "current" => $this->get(
                "step",
                "Survey",
                $surveyId,
                ''
            ),
        ];
        $aSettings["stepParticipation"] = [
            "type" => "select",
            "label" => $this->translate("Use step for participation too"),
            "help" => $this->translate("If you use step for satisfaction, you can use step for particiaption too"),
            "options" => ["1" => gT("Yes"), "0" => gT("No")],
            "current" => $this->get(
                "stepParticipation",
                "Survey",
                $surveyId,
                '1'
            ),
        ];
        if (Permission::model()->hasSurveyPermission($oSurvey->sid, 'statistics', 'read')) {
            $aSettings["AccessCodeTitle"] = [
                "type" => "info",
                "content" =>
                    "<h2>" .
                    $this->translate("Access code") .
                    "</h2>",
            ];
            $currentAccessKey = $this->get("accessKey", "Survey", $surveyId, "");
            $aSettings["accessKey"] = [
                "type" => "string",
                "label" => $this->translate("Non-administrator access code"),
                'htmlOptions' => [
                    'pattern' => "[A-Za-z0-9._~-]+",
                    'title' => sprintf(gT("Only letters, numbers, %s, %s, %s, and %s are allowed."), "'.'", "'_'", "'-'", "'~'"),
                ],
                "help" => $this->translate("Allow non-administrators to view and export these statistics using an access code. Enable this option only if you understand the security implications."),
                "current" => $currentAccessKey,
            ];
            if ($currentAccessKey) {
                $accessCodeUrl = App()->createPublicUrl("plugins/direct", [
                    "plugin" => $this->getName(),
                    "function" => "stat",
                    "sid" => $surveyId,
                    "accesskey" => $currentAccessKey
                ]);
                $aSettings["accesskeylink"] = [
                    "type" => "string",
                    "label" => $this->translate("Direct link with access key"),
                    'htmlOptions' => [
                        'readonly' => true,
                    ],
                    "current" => $accessCodeUrl
                ];
            }
        }
        $aSettings["CrossTitle"] = [
            "type" => "info",
            "content" =>
                "<h2>" .
                $this->translate("Participation tab") .
                "</h2>",
        ];
        if ($oSurvey->datestamp == "Y") {
            $aSettings["participationComment"] = [
                "type" => "html",
                "label" => $this->translate("Description for participation tab"),
                "current" => $this->get(
                    "participationComment",
                    "Survey",
                    $surveyId,
                    ""
                ),
                "height" => "8em",
                "editorOptions" => ["link" => false, "image" => false],
            ];
            $aSettings["dailyRate"] = [
                "type" => "select",
                "label" => $this->translate("Show the number of completed daily responses."),
                "options" => ["1" => gT("Yes"), "0" => gT("No")],
                "current" => $this->get(
                    "dailyRate",
                    "Survey",
                    $surveyId,
                    1
                ),
            ];
            $aSettings["dailyRateCumulative"] = [
                "type" => "select",
                "label" => $this->translate("Show the number of completed daily cumulative responses."),
                "options" => ["1" => gT("Yes"), "0" => gT("No")],
                "current" => $this->get(
                    "dailyRateCumulative",
                    "Survey",
                    $surveyId,
                    0
                ),
            ];
            if (
                $this->get(
                    "dailyRateEnterAllow",
                    null,
                    null,
                    $this->settings["dailyRateEnterAllow"]["default"]
                )
            ) {
                $aSettings["dailyRateEnter"] = [
                    "type" => "select",
                    "label" => $this->translate("Show the number of daily entries."),
                    "options" => ["1" => gT("Yes"), "0" => gT("No")],
                    "current" => $this->get(
                        "dailyRateEnter",
                        "Survey",
                        $surveyId,
                        0
                    ),
                ];
            }
            if (
                $this->get(
                    "dailyRateActionAllow",
                    null,
                    null,
                    $this->settings["dailyRateActionAllow"]["default"]
                )
            ) {
                $aSettings["dailyRateAction"] = [
                    "type" => "select",
                    "label" => $this->translate("Show the number of daily activities."),
                    "options" => ["1" => gT("Yes"), "0" => gT("No")],
                    "current" => $this->get(
                        "dailyRateAction",
                        "Survey",
                        $surveyId,
                        0
                    ),
                ];
            }
        } else {
            $aSettings["dailyRate"] = [
                "type" => "info",
                "label" => $this->translate("Survey are not date stamped: it's not possible to show daily rates."),
            ];
        }
        /* Token attribute */
        if (tableExists("{{tokens_{$surveyId}}}")) {
            $aRealTokenAttributes = array_keys(
                Yii::app()->db->schema->getTable(
                    "{{tokens_{$surveyId}}}"
                )->columns
            );
            $aRealTokenAttributes = array_combine(
                $aRealTokenAttributes,
                $aRealTokenAttributes
            );
            $aTokenAttributes = array_filter(
                Token::model($surveyId)->attributeLabels()
            );
            $aTokenAttributes = array_diff_key(
                array_replace($aRealTokenAttributes, $aTokenAttributes),
                [
                    "tid" => "tid",
                    "partcipant" => "partcipant",
                    "participant" => "participant",
                    "participant_id" => "participant_id",
                    "firstname" => "firstname",
                    "lastname" => "lastname",
                    "email" => "email",
                    "emailstatus" => "emailstatus",
                    "token" => "token",
                    "language" => "language",
                    "blacklisted" => "blacklisted",
                    "sent" => "sent",
                    "remindersent" => "remindersent",
                    "remindercount" => "remindercount",
                    "completed" => "completed",
                    "usesleft" => "usesleft",
                    "validfrom" => "validfrom",
                    "validuntil" => "validuntil",
                    "mpid" => "mpid",
                ]
            );
            if (!empty($aTokenAttributes)) {
                $aOptions = [];
                foreach ($aTokenAttributes as $attribute => $description) {
                    $aOptions[$attribute] = empty($description)
                        ? $attribute
                        : $description;
                }
                $aSettings["tokenAttributes"] = [
                    "type" => "select",
                    "label" => $this->translate("Token attributes for pivot (cross-sectional) - graph"),
                    "options" => $aOptions,
                    "htmlOptions" => ["multiple" => "multiple"],
                    "current" => $this->get(
                        "tokenAttributes",
                        "Survey",
                        $surveyId
                    ),
                ];
            }
        }
        $lang = $oSurvey->language;
        /* Single choice question */
        $oCriteria = new CdbCriteria();
        $oCriteria->condition =
            "parent_qid=0 and t.sid=:sid and questionl10ns.language=:language";
        $oCriteria->params[":sid"] = $oSurvey->sid;
        $oCriteria->params[":language"] = $oSurvey->language;
        $oCriteria->addInCondition("type", ["L", "!"]); // see "*"
        $oCriteria->order = "group_order ASC, question_order ASC";
        $aoSingleQuestion = Question::model()
            ->with("group")
            ->with("questionl10ns")
            ->findAll($oCriteria);
        if (!empty($aoSingleQuestion)) {
            $aSettings["questionCross"] = [
                "type" => "select",
                "label" => $this->translate("Question  for pivot (cross-sectional)"),
                "options" => CHtml::listData(
                    $aoSingleQuestion,
                    "qid",
                    function ($oSingleQuestion) use ($lang) {
                        return "[" . $oSingleQuestion->title . "] " .
                            viewHelper::flatEllipsizeText(
                                $oSingleQuestion->questionl10ns[$lang]->question,
                                1,
                                80,
                                "...",
                                0.6
                            );
                    }
                ),
                "htmlOptions" => ["multiple" => "multiple"],
                "current" => $this->get(
                    "questionCross",
                    "Survey",
                    $surveyId
                ),
            ];
        }
        /* numeric question */
        $oCriteria = new CdbCriteria();
        $oCriteria->condition =
            "parent_qid=0 and t.sid=:sid and questionl10ns.language=:language";
        $oCriteria->params[":sid"] = $oSurvey->sid;
        $oCriteria->params[":language"] = $lang;
        $oCriteria->addInCondition("type", [
            "L",
            "!",
            "F",
            "N",
            "K",
            "A",
            "B",
            ";",
        ]); // see "*"
        $oCriteria->order = "group_order ASC, question_order ASC";
        $aoNumericPossibleQuestion = Question::model()
            ->with("group")
            ->with("questionl10ns")
            ->findAll($oCriteria);
        $aQuestionNumeric = [];
        foreach ($aoNumericPossibleQuestion as $oQuestion) {
            switch ($oQuestion->type) {
                case "L":
                case "!":
                    // @todo : Test if have answer numeric
                    $iNumAnswers = Answer::model()
                        ->with("answerl10ns")
                        ->count(
                            "qid=:qid AND concat('',code * 1) = code AND language = :language",
                            [":qid" => $oQuestion->qid, ":language" => $lang]
                        );
                    if ($iNumAnswers) {
                        $aQuestionNumeric["{$oQuestion->qid}"] =
                            "[{$oQuestion->title}] " .
                            viewHelper::flatEllipsizeText(
                                $oQuestion->questionl10ns[$lang]->question,
                                1,
                                80,
                                "...",
                                0.6
                            );
                    }
                    break;
                case "N":
                    $aQuestionNumeric["{$oQuestion->qid}"] =
                        "[{$oQuestion->title}] " .
                        viewHelper::flatEllipsizeText(
                            $oQuestion->questionl10ns[$lang]->question,
                            1,
                            80,
                            "...",
                            0.6
                        );
                    break;
                case "K":
                    $oSubQuestions = Question::model()
                        ->with("questionl10ns")
                        ->findAll([
                            "condition" =>
                                "parent_qid = :qid AND language = :language",
                            "order" => "question_order",
                            "params" => [
                                ":qid" => $oQuestion->qid,
                                ":language" => $lang,
                            ],
                        ]);
                    foreach ($oSubQuestions as $oSubQuestion) {
                        $aQuestionNumeric["{$oSubQuestion->qid}"] =
                            "[{$oQuestion->title}_{$oSubQuestion->title}] " .
                            viewHelper::flatEllipsizeText(
                                $oQuestion->questionl10ns[$lang]->question,
                                1,
                                40,
                                "...",
                                0.6
                            ) .
                            " : " .
                            viewHelper::flatEllipsizeText(
                                $oSubQuestion->questionl10ns[$lang]->question,
                                1,
                                40,
                                "...",
                                0.6
                            );
                    }
                    break;
                case "A":
                case "B":
                    $oSubQuestions = Question::model()->with("questionl10ns")->findAll([
                        "condition" => "parent_qid = :parent_qid AND language=:language",
                        "order" => "question_order",
                        "params" => [
                            ":parent_qid" => $oQuestion->qid,
                            ":language" => $lang,
                        ],
                    ]);
                    foreach ($oSubQuestions as $oSubQuestion) {
                        $aQuestionNumeric["{$oSubQuestion->qid}"] =
                            "[{$oQuestion->title}_{$oSubQuestion->title}] " .
                            viewHelper::flatEllipsizeText(
                                $oQuestion->questionl10ns[$lang]->question,
                                1,
                                40,
                                "...",
                                0.6
                            ) .
                            " : " .
                            viewHelper::flatEllipsizeText(
                                $oSubQuestion->questionl10ns[$lang]->question,
                                1,
                                40,
                                "...",
                                0.6
                            );
                    }
                    break;
                case "F":
                    $iNumAnswers = Answer::model()
                        ->with("answerl10ns")
                        ->count("qid=:qid AND concat('',code * 1) = code", [
                            ":qid" => $oQuestion->qid,
                        ]);
                    if ($iNumAnswers) {
                        $oSubQuestions = Question::model()->with("questionl10ns")->findAll([
                            "condition" =>
                                "parent_qid=:qid AND language=:language",
                            "order" => "question_order",
                            "params" => [
                                ":qid" => $oQuestion->qid,
                                ":language" => $oSurvey->language,
                            ],
                        ]);
                        foreach ($oSubQuestions as $oSubQuestion) {
                            $aQuestionNumeric["{$oSubQuestion->qid}"] =
                                "[{$oQuestion->title}_{$oSubQuestion->title}] " .
                                viewHelper::flatEllipsizeText(
                                    $oQuestion->questionl10ns[$lang]->question,
                                    1,
                                    40,
                                    "...",
                                    0.6
                                ) .
                                " : " .
                                viewHelper::flatEllipsizeText(
                                    $oSubQuestion->questionl10ns[$lang]->question,
                                    1,
                                    40,
                                    "...",
                                    0.6
                                );
                        }
                    }
                    break;
                case ";":
                    //~ // Find if have starRating system
                    $aoSubQuestionX = Question::model()->with("questionl10ns")->findAll([
                        "condition" =>
                            "parent_qid=:parent_qid and language=:language and scale_id=:scale_id",
                        "params" => [
                            ":parent_qid" => $oQuestion->qid,
                            ":language" => $lang,
                            ":scale_id" => 1,
                        ],
                        "index" => "qid",
                    ]);
                    $oCriteria = new CDbCriteria();
                    $oCriteria->condition = "attribute='arrayTextAdaptation'";
                    $oCriteria->addSearchCondition("value", "star%", false);
                    $oCriteria->addInCondition(
                        "qid",
                        CHtml::listData($aoSubQuestionX, "qid", "qid")
                    );
                    $iExistingAttribute = QuestionAttribute::model()->count(
                        $oCriteria
                    );
                    if ($iExistingAttribute) {
                        $oSubQuestions = Question::model()->with("questionl10ns")->findAll(
                            [
                                "condition" =>
                                    "parent_qid=:qid AND questionl10ns.language=:language AND scale_id=:scale_id",
                                "order" => "question_order",
                                "params" => [
                                    ":qid" => $oQuestion->qid,
                                    ":language" => $oSurvey->language,
                                    ":scale_id" => 0,
                                ],
                            ]
                        );
                        foreach ($oSubQuestions as $oSubQuestion) {
                            $aQuestionNumeric["{$oSubQuestion->qid}"] =
                                "[{$oQuestion->title}_{$oSubQuestion->title}] " .
                                viewHelper::flatEllipsizeText(
                                    $oQuestion->questionl10ns[$lang]->question,
                                    1,
                                    40,
                                    "...",
                                    0.6
                                ) .
                                " : " .
                                viewHelper::flatEllipsizeText(
                                    $oSubQuestion->questionl10ns[$lang]->question,
                                    1,
                                    40,
                                    "...",
                                    0.6
                                );
                        }
                    }
                    break;
                default:
                    break;
            }
        }
        if (!empty($aQuestionNumeric)) {
            $aSettings["SatTitle"] = [
                "type" => "info",
                "content" =>
                    "<h2>" .
                    $this->translate("Satisfaction tab") .
                    "</h2>",
            ];
            $aSettings["satisfactionComment"] = [
                "type" => "html",
                "label" => $this->translate("Description for satisfaction tab"),
                "current" => $this->get(
                    "satisfactionComment",
                    "Survey",
                    $surveyId,
                    ""
                ),
                "height" => "8em",
                "editorOptions" => ["link" => false, "image" => false],
            ];
            $aSettings["questionNumeric"] = [
                "type" => "select",
                "label" => $this->translate("Questions of satisfaction"),
                "options" => $aQuestionNumeric,
                "htmlOptions" => ["multiple" => "multiple"],
                "current" => $this->get(
                    "questionNumeric",
                    "Survey",
                    $surveyId
                ),
            ];
            if (!empty($aTokenAttributes)) {
                $aOptions = [];
                foreach ($aTokenAttributes as $attribute => $description) {
                    $aOptions[$attribute] = empty($description)
                        ? $attribute
                        : $description;
                }
                $aSettings["tokenAttributesSatisfaction"] = [
                    "type" => "select",
                    "label" => $this->translate("Token attributes for pivot (graph)"),
                    "options" => $aOptions,
                    "htmlOptions" => ["multiple" => "multiple"],
                    "current" => $this->get(
                        "tokenAttributesSatisfaction",
                        "Survey",
                        $surveyId
                    ),
                ];
                $aSettings["tokenAttributesSatisfactionTable"] = [
                    "type" => "select",
                    "label" => $this->translate("Token attributes for pivot (table)"),
                    "options" => $aOptions,
                    "htmlOptions" => ["multiple" => "multiple"],
                    "current" => $this->get(
                        "tokenAttributesSatisfactionTable",
                        "Survey",
                        $surveyId
                    ),
                ];
            }
            if (!empty($aoSingleQuestion)) {
                $aSettings["questionCrossSatisfaction"] = [
                    "type" => "select",
                    "label" => $this->translate("Question for pivot (in graphic)"),
                    "options" => CHtml::listData(
                        $aoSingleQuestion,
                        "qid",
                        function ($oSingleQuestion) use ($lang) {
                            return "[" .
                                $oSingleQuestion->title .
                                "] " .
                                viewHelper::flatEllipsizeText(
                                    $oSingleQuestion->questionl10ns[$lang]->question,
                                    1,
                                    80,
                                    "...",
                                    0.6
                                );
                        }
                    ),
                    "htmlOptions" => ["multiple" => "multiple"],
                    "current" => $this->get(
                        "questionCrossSatisfaction",
                        "Survey",
                        $surveyId
                    ),
                ];
                $aSettings["questionCrossSatisfactionTable"] = [
                    "type" => "select",
                    "label" => $this->translate("Question for pivot (in array)"),
                    "options" => CHtml::listData(
                        $aoSingleQuestion,
                        "qid",
                        function ($oSingleQuestion) use ($lang) {
                            return "[" .
                                $oSingleQuestion->title .
                                "] " .
                                viewHelper::flatEllipsizeText(
                                    $oSingleQuestion->questionl10ns[$lang]->question,
                                    1,
                                    80,
                                    "...",
                                    0.6
                                );
                        }
                    ),
                    "htmlOptions" => ["multiple" => "multiple"],
                    "current" => $this->get(
                        "questionCrossSatisfactionTable",
                        "Survey",
                        $surveyId
                    ),
                ];
            }
        }
        $aSettings["IndexTitle"] = [
            "type" => "info",
            "content" =>
                "<h2>" .
                $this->translate("Create index in database") .
                "</h2>",
        ];
        $aSettings["indexToken"] = [
            "type" => "boolean",
            "label" => $this->translate("Create index on token table"),
            "current" => 0,
        ];
        $aSettings["indexResponse"] = [
            "type" => "boolean",
            "label" => $this->translate("Create index on response table"),
            "current" => 0,
        ];
        return [
            $this->translate('Settings') => $aSettings
        ];
    }

    /** Save the settings **/
    public function actionSaveSettings($surveyId)
    {
        $oSurvey = Survey::model()->findByPk($surveyId);
        if (!$oSurvey) {
            throw new CHttpException(404, gT("This survey does not seem to exist."));
        }
        if (!Permission::model()->hasSurveyPermission($oSurvey->sid, 'surveysettings', 'update')) {
            throw new CHttpException(403);
        }
        $aSettings = App()->getRequest()->getPost('quickStatAdminParticipationAndStat');
        if (empty($aSettings)) {
            throw new CHttpException(400);
        }
        /* Fix not set dropdown */
        $aSettings["tokenAttributes"] = isset($aSettings["tokenAttributes"])
            ? $aSettings["tokenAttributes"]
            : null;
        $aSettings["questionCross"] = isset($aSettings["questionCross"])
            ? $aSettings["questionCross"]
            : null;
        $aSettings["questionNumeric"] = isset($aSettings["questionNumeric"])
            ? $aSettings["questionNumeric"]
            : null;
        $aSettings["tokenAttributesSatisfaction"] = isset(
            $aSettings["tokenAttributesSatisfaction"]
        )
            ? $aSettings["tokenAttributesSatisfaction"]
            : null;
        $aSettings["tokenAttributesSatisfactionTable"] = isset(
            $aSettings["tokenAttributesSatisfactionTable"]
        )
            ? $aSettings["tokenAttributesSatisfactionTable"]
            : null;
        $aSettings["questionCrossSatisfaction"] = isset(
            $aSettings["questionCrossSatisfaction"]
        )
            ? $aSettings["questionCrossSatisfaction"]
            : null;
        $aSettings["questionCrossSatisfactionTable"] = isset(
            $aSettings["questionCrossSatisfactionTable"]
        )
            ? $aSettings["questionCrossSatisfactionTable"]
            : null;
        /* Keep value but remove from save */
        $indexToken = $aSettings['indexToken'] ?? false;
        $indexResponse = $aSettings['indexResponse'] ?? false;
        unset($aSettings['indexToken']);
        unset($aSettings['indexResponse']);
        /* Filter accessKey */
        if (Permission::model()->hasSurveyPermission($oSurvey->sid, 'statistics', 'read')) {
            $aSettings['accessKey'] = preg_replace('/[^A-Za-z0-9._~-]/', '', strval($aSettings['accessKey'] ?? ''));
        } else {
            unset($aSettings['accessKey']);
        }
        /* Non needed value */
        unset($aSettings['statlink']);
        unset($aSettings['accesskeylink']);

        foreach ($aSettings as $name => $value) {
            $this->set($name, $value, 'Survey', $surveyId);
        }
        /* Index */
        if ($indexToken) {
            $this->createTokenIndex($surveyId);
        }
        if ($indexResponse) {
            $this->createResponseIndex($surveyId);
        }
        $redirectUrl = Yii::app()->createUrl('admin/pluginhelper/sa/sidebody', array('plugin' => get_class($this),'method' => 'actionSettings','surveyId' => $surveyId));
        Yii::app()->getRequest()->redirect($redirectUrl, true, 303);
    }

    /**
     * Create needed index on token table
     * @param integer $surveyId
     * @return void
     */
    private function createTokenIndex($surveyId)
    {
        if (!tableExists("{{tokens_{$surveyId}}}")) {
            /* @todo flash message*/
            return;
        }
        $allTokenAttributes = array_unique(array_merge(
            (array) $this->get("tokenAttributes", "Survey", $surveyId),
            (array) $this->get("tokenAttributesSatisfaction", "Survey", $surveyId),
            (array) $this->get("tokenAttributesSatisfactionTable", "Survey", $surveyId)
        ));
        $aRealTokenAttributes = array_keys(
            Yii::app()->db->schema->getTable(
                "{{tokens_{$surveyId}}}"
            )->columns
        );
        foreach ($allTokenAttributes as $attribute) {
            if (in_array($attribute, $aRealTokenAttributes)) {
                $indexName = "qickstat_" . $attribute;
                try {
                    App()->getDb()->createCommand()->dropIndex($indexName, "{{tokens_{$surveyId}}}");
                } catch (Exception $ex) {
                    // index not exist : not an error
                }
                if (App()->getDb()->createCommand()->createIndex($indexName, "{{tokens_{$surveyId}}}", $attribute)) {
                    App()->setFlashMessage(
                        sprintf($this->gT("Index created on attribute %s token table."), $attribute),
                        "success"
                    );
                } else {
                    App()->setFlashMessage(
                        sprintf($this->gT("Unblae to create index on attribute %s token table."), $attribute),
                        "warnnng"
                    );
                }
            }
        }
    }

    /**
     * Create needed index on response table
     * @param integer $surveyId
     * @return void
     */
    private function createResponseIndex($surveyId)
    {
        if (!self::isSurveyActive($surveyId)) {
            /* @todo flash message*/
            return;
        }
        $tablename = "{{responses_{$surveyId}}}";
        if (App()->getConfig('versionnumber') < 7) {
            $tablename = "{{survey_{$surveyId}}}";
        }
        $allQuestionsIds = array_unique(array_merge(
            (array) $this->get("questionCross", "Survey", $surveyId),
            (array) $this->get("questionCrossSatisfaction", "Survey", $surveyId),
            (array) $this->get("questionCrossSatisfactionTable", "Survey", $surveyId)
        ));
        $aRealReponseAttributes = array_keys(
            Yii::app()->db->schema->getTable(
                "{$tablename}"
            )->columns
        );

        foreach ($allQuestionsIds as $qid) {
            $oQuestion = Question::model()->find("qid = :qid", [':qid' => $qid]);
            if (!$oQuestion) {
                continue;
            }
            $column = "Q" . $oQuestion->qid;
            if (App()->getConfig('versionnumber') < 7) {
                $column = $surveyId . "X" . $oQuestion->gid . "X" . $oQuestion->qid;
            }
            if (in_array($column, $aRealReponseAttributes)) {
                $indexName = "qickstat_" . $column;
                try {
                    App()->getDb()->createCommand()->dropIndex($indexName, "{$tablename}");
                } catch (Exception $ex) {
                    // index not exist : not an error
                }
                if (App()->getDb()->createCommand()->createIndex($indexName, "{$tablename}", $column)) {
                    App()->setFlashMessage(
                        sprintf($this->gT("Index created on column %s (question %s) response table."), $column, $oQuestion->title),
                        "success"
                    );
                } else {
                    App()->setFlashMessage(
                        sprintf($this->gT("Unable to create index on column %s (question %s) response table."), $column, $oQuestion->title),
                        "warnnng"
                    );
                }
            }
        }
    }

    /**
     * The request action test
     */
    public function newDirectRequest()
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        if ($this->event->get("target") != __CLASS__) {
            return;
        }
        Yii::import("application.helpers.viewHelper");
        $sid = $this->api->getRequest()->getParam("sid");
        if ($sid) {
            $this->validateSurveyId($sid);
            /* sid is valid : get the Survey */
            $this->iSurveyId = $sid;
        }
        $this->setBaseLanguage($this->iSurveyId);
        if (!$sid && Yii::app()->user->getIsGuest()) {
            App()->user->setReturnUrl(App()->request->requestUri);
            App()->controller->redirect(["/admin/authentication"]);
        }

        $sAction = $this->event->get("function");
        if ($sid && (!$sAction || $sAction == 'stat')) {
            $sAction = "participation";
        }
        if (!$sid && in_array($sAction, ["participation", "satisfaction", "export"])) {
            throw new CHttpException(400, gT("Invalid parameters."));
        }
        switch ($sAction) {
            case "participation":
                $this->actionParticipation();
                break;
            case "satisfaction":
                $this->actionSatisfaction();
                break;
            case "export":
                $this->actionExportData();
                break;
            case "list":
            default:
                $this->actionList();
                break;
        }
    }

    /**
     * Get participation for this survey
     * @return void (rendering)
     */
    public function actionParticipation()
    {
        if (empty($this->iSurveyId)) {
            throw new CHttpException(500);
        }
        $oSurvey = Survey::model()->findByPk($this->iSurveyId);
        $participationRenderData = $this->getParticipationRenderData($oSurvey);
        $this->aRenderData = array_merge($this->aRenderData, $participationRenderData);
        $this->ownRender("participation");
    }

    /**
     * Get the render data for participation
     * @param \Survey oSurvey
     * @return array
     */
    public function getParticipationRenderData(\Survey $oSurvey): array
    {
        $this->setSurvey($oSurvey);
        $renderData = [];
        $surveyId = $oSurvey->sid;
        if ($oSurvey->datestamp == "Y") {
            if ($this->get("dailyRate", "Survey", $surveyId, 1)) {
                $aDailyResponses = $renderData[
                    "aDailyResponses"
                ] = $this->getDailyResponsesRate($surveyId);
            }
            if ($this->get("dailyRateCumulative", "Survey", $surveyId, 1)) {
                $aDailyResponses = isset($aDailyResponses)
                    ? $aDailyResponses
                    : $this->getDailyResponsesRate($surveyId);
                if (!empty($aDailyResponses)) {
                    $aDailyResponsesCumulative = [];
                    $sum = 0;
                    foreach ($aDailyResponses as $date => $nb) {
                        $sum += $nb;
                        $aDailyResponsesCumulative[$date] = $sum;
                    }
                    $renderData[
                        "aDailyResponsesCumulative"
                    ] = $aDailyResponsesCumulative;
                }
            }
            if (
                $this->get("dailyRateEnter", "Survey", $surveyId, 0) &&
                $this->get(
                    "dailyRateEnterAllow",
                    null,
                    null,
                    $this->settings["dailyRateEnterAllow"]["default"]
                )
            ) {
                $renderData[
                    "aDailyEnter"
                ] = $this->getDailyResponsesRate($this->iSurveyId, "startdate");
            }
            if (
                $this->get("dailyRateAction", "Survey", $surveyId, 0) &&
                $this->get(
                    "dailyRateActionAllow",
                    null,
                    null,
                    $this->settings["dailyRateActionAllow"]["default"]
                )
            ) {
                $renderData[
                    "aDailyAction"
                ] = $this->getDailyResponsesRate($this->iSurveyId, "datestamp");
            }
        }
        $renderData["aResponses"] = $this->getParticipationRate(
            $this->iSurveyId
        );
        $renderData["htmlComment"] = $this->get(
            "participationComment",
            "Survey",
            $surveyId,
            ""
        );
        return $renderData;
    }

    /**
     * get the participation rate data
     * @return []
     */
    protected function getParticipationRate($iSurveyId)
    {
        $oSurvey = Survey::model()->findByPk($iSurveyId);
        /* decompte */
        $aResponses = [];
        /* Total */
        $max = $this->get("numberMax", "Survey", $iSurveyId, 0);
        $source = 'estimate';
        if (intval($max) == 0 && tableExists("{{tokens_{$iSurveyId}}}")) {
            $max = Token::model($iSurveyId)->count(); // see with Token::model($iSurveyId)->empty()->count()
            $source = 'token';
        }
        $condition = "submitdate IS NOT NULL";
        $step = $this->get("step", "Survey", $iSurveyId, '');
        if ($step !== '' && $this->get("stepParticipation", "Survey", $iSurveyId, 0)) {
            if (intval($step) >= 0) {
                $condition = "lastpage >= " . intval($step);
            } else {
                $condition = "";
            }
        }
        $aResponses["total"] = [
            "title" => $this->translate("Globally"),
            "max" => $max,
            "data" => [
                [
                    "title" => $this->translate("Total responses"),
                    "max" => $max,
                    "completed" => Response::model($iSurveyId)->count($condition),
                ],
            ],
            'source' => $source
        ];
        /* by token */
        $aTokenCross = $this->get("tokenAttributes", "Survey", $iSurveyId);
        if (!empty($aTokenCross) && tableExists("{{tokens_{$iSurveyId}}}")) {
            $aValidAttributes = Token::model($iSurveyId)->attributeLabels();
            $andCondition = " AND completed!='N' AND completed<>'' AND responses.submitdate IS NOT NULL";
            $step = $this->get("step", "Survey", $iSurveyId, '');
            if ($step !== '' && $this->get("stepParticipation", "Survey", $iSurveyId, 0)) {
                if (intval($step) >= 0) {
                    $andCondition = " AND responses.lastpage >= " . intval($step);
                } else {
                    $andCondition = "";
                }
            }
            foreach ($aTokenCross as $tokenCross) {
                if (array_key_exists($tokenCross, $aValidAttributes)) {
                    /* The list */
                    $aTokenValues = $this->getTokenValues($tokenCross);
                    $aData = [];
                    $globalMax = 0;
                    foreach ($aTokenValues as $sTokenValue) {
                        $max = Token::model($iSurveyId)->count(
                            "$tokenCross=:tokenvalue",
                            [":tokenvalue" => $sTokenValue]
                        );
                        $globalMax += $max;
                        $aData[] = [
                            "title" => viewHelper::flatEllipsizeText(
                                $sTokenValue,
                                true,
                                false
                            ),
                            "max" => $max,
                            "completed" => Token::model($iSurveyId)
                                ->with("responses")
                                ->count(
                                    "$tokenCross=:tokenvalue" . $andCondition,
                                    [":tokenvalue" => $sTokenValue]
                                ),
                        ];
                    }
                    $aResponses[$tokenCross] = [
                        "title" => viewHelper::flatEllipsizeText(
                            $aValidAttributes[$tokenCross],
                            true,
                            false
                        ),
                        "max" => $max,
                        "data" => $aData,
                        'source' => 'token'
                    ];
                }
            }
        }
        /* by questions */
        $aQuestionsCross = $this->get("questionCross", "Survey", $iSurveyId);
        if (!empty($aQuestionsCross)) {
            $oCriteria = new CdbCriteria();
            $oCriteria->condition = "t.sid=:sid and questionl10ns.language=:language";
            $oCriteria->params[":sid"] = $oSurvey->sid;
            $oCriteria->params[":language"] = $this->surveyLanguage;
            $oCriteria->addInCondition("type", ["L", "!"]);
            $oCriteria->addInCondition("t.qid", $aQuestionsCross);
            $oCriteria->order = "group_order ASC, question_order ASC";
            $aoSingleQuestion = Question::model()
                ->with("group")
                ->with("questionl10ns")
                ->findAll($oCriteria);
            $condition = "submitdate IS NOT NULL";
            $step = $this->get("step", "Survey", $iSurveyId, '');
            if ($step !== '' && $this->get("stepParticipation", "Survey", $iSurveyId, 0)) {
                if (intval($step) >= 0) {
                    $condition = "lastpage >= " . intval($step);
                } else {
                    $condition = "";
                }
            }
            if (!empty($aoSingleQuestion)) {
                foreach ($aoSingleQuestion as $oSingleQuestion) {
                    $sColumn = "Q{$oSingleQuestion->qid}";
                    if (App()->getConfig('versionnumber') < 7) {
                        $sColumn = "{$oSingleQuestion->sid}X{$oSingleQuestion->gid}X{$oSingleQuestion->qid}";
                    }
                    $aData = [];
                    $oAnswers = Answer::model()
                        ->with("answerl10ns")
                        ->findAll([
                        "condition" => "t.qid=:qid and answerl10ns.language=:language",
                        "order" => "sortorder",
                        "params" => [
                            ":qid" => $oSingleQuestion->qid,
                            ":language" => $this->surveyLanguage,
                        ],
                    ]);
                    $globalMax = 0;
                    foreach ($oAnswers as $oAnswer) {
                        $countCriteria = new CdbCriteria();
                        if ($condition) {
                            $countCriteria->condition = $condition;
                        }
                        $countCriteria->compare(
                            Yii::app()->db->quoteColumnName($sColumn),
                            $oAnswer->code
                        );
                        $globalMax += $oAnswer->assessment_value;
                        $aData[] = [
                            "title" => viewHelper::flatEllipsizeText(
                                $oAnswer->answerl10ns[$this->surveyLanguage]->answer,
                                true,
                                false
                            ),
                            "max" => $oAnswer->assessment_value,
                            "completed" => Response::model($iSurveyId)->count(
                                $countCriteria
                            ),
                        ];
                    }
                    $aResponses[$oSingleQuestion->title] = [
                        "title" => viewHelper::flatEllipsizeText(
                            $oSingleQuestion->questionl10ns[$this->surveyLanguage]->question,
                            true,
                            false
                        ),
                        "max" => $globalMax,
                        "data" => $aData,
                        'source' => 'estimate'
                    ];
                }
            }
        }
        return $aResponses;
    }

    /**
     * Show Satisfaction for this survey
     * @return void (rendering)
     */
    public function actionSatisfaction()
    {
        if (empty($this->iSurveyId)) {
            throw new CHttpException(500);
        }
        $surveyId = $this->iSurveyId;
        $oSurvey = Survey::model()->findByPk($surveyId);
        $satisfactionRenderData = $this->getSatisfactionRenderData($oSurvey);
        $this->aRenderData = array_merge($this->aRenderData, $satisfactionRenderData);
        $this->ownRender("satisfaction");
    }

    /**
     * Get the render data for Satisfaction
     * @param \Survey oSurvey
     * @return array
     */
    public function getSatisfactionRenderData(\Survey $oSurvey): array
    {
        $this->setSurvey($oSurvey);
        $renderData = [];
        $aResponses = [];
        /* Global */
        $aQuestionsNumeric = $this->get(
            "questionNumeric",
            "Survey",
            $this->iSurveyId,
            []
        );
        $aData = [];
        $aDataInfos = []; // Use some data for all datas : less easy than $aData['total'][$sColumn]
        foreach ($aQuestionsNumeric as $iQuestionNumeric) {
            /* find the code column */
            $oQuestion = Question::model()->with("questionl10ns")->find(
                "t.sid=:sid AND t.qid=:qid AND questionl10ns.language=:language",
                [
                    ":sid" => $this->iSurveyId,
                    ":qid" => $iQuestionNumeric,
                    ":language" => $this->surveyLanguage,
                ]
            );
            if ($oQuestion) {
                $maxByQuestion = 0;
                if ($oQuestion->parent_qid) {
                    $oParentQuestion = Question::model()->with("questionl10ns")->find(
                        "t.qid=:qid AND questionl10ns.language=:language",
                        [
                            ":qid" => $oQuestion->parent_qid,
                            ":language" => $this->surveyLanguage,
                        ]
                    );
                    if ($oParentQuestion->type == ";") {
                        $aoSubQuestionX = Question::model()->with("questionl10ns")->findAll(
                            [
                                "condition" =>
                                    "parent_qid=:parent_qid and questionl10ns.language=:language and scale_id=:scale_id",
                                "params" => [
                                    ":parent_qid" => $oParentQuestion->qid,
                                    ":language" => $this->surveyLanguage,
                                    ":scale_id" => 1,
                                ],
                                "index" => "qid",
                            ]
                        );
                        $oCriteria = new CDbCriteria();
                        $oCriteria->condition =
                            "attribute='arrayTextAdaptation'";
                        $oCriteria->addSearchCondition("value", "star%", false);
                        $oCriteria->addInCondition(
                            "qid",
                            CHtml::listData($aoSubQuestionX, "qid", "qid")
                        );
                        $oExistingAttribute = QuestionAttribute::model()->find(
                            $oCriteria
                        );
                        if ($oExistingAttribute) {
                            $maxByQuestion = intval(
                                substr($oExistingAttribute->value, 4)
                            );
                            $oXQuestion = Question::model()->with("questionl10ns")->find(
                                "t.qid=:qid AND questionl10ns.language=:language",
                                [
                                    ":qid" => $oExistingAttribute->qid,
                                    ":language" => $this->surveyLanguage,
                                ]
                            );
                            if ($oXQuestion) {
                                $sColumnName = "Q{$oParentQuestion->qid}_S{$oQuestion->qid}_S{$oXQuestion->qid}";
                                if (App()->getConfig('versionnumber') < 7) {
                                    $sColumnName = "{$oParentQuestion->sid}X{$oParentQuestion->gid}X{$oParentQuestion->qid}{$oQuestion->title}_{$oXQuestion->title}";
                                }
                            }
                        }
                    } else {
                        $sColumnName = "Q{$oParentQuestion->qid}_S{$oQuestion->qid}";
                        if (App()->getConfig('versionnumber') < 7) {
                            $sColumnName = "{$oParentQuestion->sid}X{$oParentQuestion->gid}X{$oParentQuestion->qid}{$oQuestion->title}";
                        }
                        switch ($oParentQuestion->type) {
                            case "F":
                                $sQuotedColumn = Yii::app()->db->quoteColumnName(
                                    "code"
                                );
                                $oCriteria = new CDbCriteria();
                                $oCriteria->condition = "qid =:qid";
                                $oCriteria->addCondition(
                                    "concat('',{$sQuotedColumn} * 1) = {$sQuotedColumn}"
                                );
                                $oCriteria->params[":qid"] =
                                    $oParentQuestion->qid;
                                $maxByQuestion = max(
                                    CHtml::listData(
                                        Answer::model()->findAll($oCriteria),
                                        "code",
                                        "code"
                                    )
                                );
                                break;
                            case "A":
                                $maxByQuestion = 5;
                                break;
                            case "B":
                                $maxByQuestion = 10;
                                break;
                        }
                    }
                    $sTitle =
                        "<small>" .
                        viewHelper::flatEllipsizeText(
                            $oParentQuestion->questionl10ns[$this->surveyLanguage]->question,
                            true,
                            false
                        ) .
                        "</small> \n" .
                        viewHelper::flatEllipsizeText(
                            $oQuestion->questionl10ns[$this->surveyLanguage]->question,
                            true,
                            false
                        );
                } else {
                    $sColumnName = "Q{$oQuestion->qid}";
                    if (App()->getConfig('versionnumber') < 7) {
                        $sColumnName = "{$oQuestion->sid}X{$oQuestion->gid}X{$oQuestion->qid}";
                    }
                    $sTitle = viewHelper::flatEllipsizeText(
                        $oQuestion->questionl10ns[$this->surveyLanguage]->question,
                        true,
                        false
                    );
                    if (in_array($oQuestion->type, ["L", "!"])) {
                        $sQuotedColumn = Yii::app()->db->quoteColumnName(
                            "code"
                        );
                        $oCriteria = new CDbCriteria();
                        $oCriteria->condition = "qid =:qid";
                        $oCriteria->addCondition(
                            "concat('',{$sQuotedColumn} * 1) = {$sQuotedColumn}"
                        );
                        $oCriteria->params[":qid"] = $oQuestion->qid;
                        $maxByQuestion = max(
                            CHtml::listData(
                                Answer::model()->findAll($oCriteria),
                                "code",
                                "code"
                            )
                        );
                    } elseif ($oQuestion->type == "N") {
                    }
                }
                if (!empty($sColumnName)) {
                    $iCount = $this->getCountNumeric($sColumnName);
                    if ($iCount) {
                        $aDataInfos[$sColumnName] = [
                            "title" => $sTitle,
                            "min" => 0,
                            "max" => max(
                                $maxByQuestion,
                                $this->getMax($sColumnName)
                            ),
                        ];
                        $aData[$sColumnName] = [
                            "title" => $sTitle,
                            "min" => 0,
                            "max" => max(
                                $maxByQuestion,
                                $this->getMax($sColumnName)
                            ),
                            "datas" => [
                                [
                                    "title" => $this->translate(
                                        "Total responses"
                                    ),
                                    "count" => $iCount,
                                    "average" => $this->getAverage(
                                        $sColumnName
                                    ),
                                ],
                            ],
                        ];
                    }
                }
            }
        }
        if (!empty($aData)) {
            $aResponses["total"] = [
                "title" => $this->translate("Globally"),
                "aSatisfactions" => $aData,
                'type' => 'graph'
            ];
        }
        /* Do it for each */
        $aTokenCrossGraph =  (array) $this->get(
            "tokenAttributesSatisfaction",
            "Survey",
            $this->iSurveyId,
            []
        );
        $aTokenCrossTable = (array) $this->get(
            "tokenAttributesSatisfactionTable",
            "Survey",
            $this->iSurveyId,
            []
        );
        $aAllTokenCross = array_unique(array_merge($aTokenCrossGraph, $aTokenCrossTable));
        if (
            !empty($aDataInfos) &&
            !empty($aAllTokenCross) &&
            tableExists("{{tokens_{$this->iSurveyId}}}")
        ) {
            $aValidAttributes = Token::model(
                $this->iSurveyId
            )->attributeLabels();
            $aAllTokenCross = array_intersect(
                array_keys($aValidAttributes),
                $aAllTokenCross
            );
            foreach ($aAllTokenCross as $tokenCross) {
                $aTokenValues = $this->getTokenValues($tokenCross);
                $aData = [];
                foreach ($aDataInfos as $sColumnName => $aDataInfo) {
                    $aData = [];
                    foreach ($aTokenValues as $sTokenValue) {
                        $value = $sTokenValue;
                        $aData[] = [
                            "title" => viewHelper::flatEllipsizeText(
                                $sTokenValue,
                                true,
                                false
                            ),
                            "count" => $this->getCountNumeric(
                                $sColumnName,
                                [$tokenCross => $sTokenValue]
                            ),
                            "average" => $this->getAverage($sColumnName, [
                                $tokenCross => $sTokenValue,
                            ]),
                        ];
                    }
                    if (!empty($aData)) {
                        $aSatisfaction[$sColumnName] = [
                            "title" => $aDataInfos[$sColumnName]["title"],
                            "min" => 0,
                            "max" => $aDataInfos[$sColumnName]["max"],
                            "datas" => $aData,
                        ];
                    }
                }
                if (in_array($tokenCross, $aTokenCrossGraph)) {
                    $aResponses[$tokenCross . "_graph"] = [
                        "title" => viewHelper::flatEllipsizeText(
                            $aValidAttributes[$tokenCross],
                            true,
                            false
                        ),
                        "aSatisfactions" => $aSatisfaction,
                        'type' => 'graph'
                    ];
                }
                if (in_array($tokenCross, $aTokenCrossTable)) {
                    $aResponses[$tokenCross . "_table"] = [
                        "title" => viewHelper::flatEllipsizeText(
                            $aValidAttributes[$tokenCross],
                            true,
                            false
                        ),
                        "aSatisfactions" => $aSatisfaction,
                        'type' => 'table'
                    ];
                }
            }
        }
        /* Recup all question */
        $oCriteria = new CdbCriteria();
        $oCriteria->condition = "t.sid=:sid and questionl10ns.language=:language";
        $oCriteria->select = "qid";
        $oCriteria->params[":sid"] = $oSurvey->sid;
        $oCriteria->params[":language"] = $this->surveyLanguage;
        $oCriteria->addInCondition("type", ["L", "!"]); // see "*"
        $oCriteria->order = "group_order ASC, question_order ASC";
        $aoAllSingleQuestion = Question::model()
            ->with("questionl10ns")
            ->with("group")
            ->findAll($oCriteria);
        $aAllSingleQuestion = CHtml::listData(
            $aoAllSingleQuestion,
            "qid",
            "qid"
        );
        /* Type graphique */
        $aQuestionsCross = (array) $this->get(
            "questionCrossSatisfaction",
            "Survey",
            $this->iSurveyId
        );
        /* Type tableau */
        $aQuestionsCrossTable = (array) $this->get(
            "questionCrossSatisfactionTable",
            "Survey",
            $this->iSurveyId
        );
        $aAllQuestionsCross = array_intersect(
            $aAllSingleQuestion,
            array_unique(array_merge($aQuestionsCross, $aQuestionsCrossTable))
        );
        /* merge grahique + tableau */
        /* All question filter array */
        if (!empty($aDataInfos) && !empty($aAllQuestionsCross)) {
            $oCriteria = new CdbCriteria();
            $oCriteria->condition = "t.sid=:sid and questionl10ns.language=:language";
            $oCriteria->params[":sid"] = $oSurvey->sid;
            $oCriteria->params[":language"] = $this->surveyLanguage;
            $oCriteria->addInCondition("type", ["L", "!"]);
            $oCriteria->addInCondition("t.qid", $aAllQuestionsCross);
            $oCriteria->order = "group_order ASC, question_order ASC";
            $aoSingleQuestion = Question::model()
                ->with("group")
                ->with("questionl10ns")
                ->findAll($oCriteria);
            if (!empty($aoSingleQuestion)) {
                foreach ($aoSingleQuestion as $oSingleQuestion) {
                    $sColumn = "Q{$oSingleQuestion->qid}";
                    if (App()->getConfig('versionnumber') < 7) {
                        $sColumn = "{$oSingleQuestion->sid}X{$oSingleQuestion->gid}X{$oSingleQuestion->qid}";
                    }
                    $oAnswers = Answer::model()
                        ->with("answerl10ns")
                        ->findAll([
                        "condition" => "t.qid=:qid and answerl10ns.language=:language",
                        "order" => "sortorder",
                        "params" => [
                            ":qid" => $oSingleQuestion->qid,
                            ":language" => $this->surveyLanguage,
                        ],
                    ]);
                    $aAnswers = Chtml::listData($oAnswers, "code", "answerl10ns.{$this->surveyLanguage}.answer");
                    $aData = [];
                    foreach ($aDataInfos as $sColumnName => $aDataInfo) {
                        $aData = [];
                        foreach ($aAnswers as $sCode => $sAnswer) {
                            $aData[] = [
                                "title" => viewHelper::flatEllipsizeText(
                                    $sAnswer,
                                    true,
                                    false
                                ),
                                "count" => $this->getCountNumeric(
                                    $sColumnName,
                                    [$sColumn => $sCode]
                                ),
                                "average" => $this->getAverage($sColumnName, [
                                    $sColumn => $sCode,
                                ]),
                            ];
                        }
                        if (!empty($aData)) {
                            $aSatisfaction[$sColumnName] = [
                                "title" => $aDataInfos[$sColumnName]["title"],
                                "min" => 0,
                                "max" => $aDataInfos[$sColumnName]["max"],
                                "datas" => $aData,
                            ];
                        }
                    }
                    if (in_array($oSingleQuestion->qid, $aQuestionsCross)) {
                        $aResponses[$sColumn . "_graph"] = [
                            "title" => viewHelper::flatEllipsizeText(
                                $oSingleQuestion->questionl10ns[$this->surveyLanguage]->question,
                                true,
                                false
                            ),
                            "aSatisfactions" => $aSatisfaction,
                            "type" => "graph",
                        ];
                    }
                    if (
                        in_array($oSingleQuestion->qid, $aQuestionsCrossTable)
                    ) {
                        $aResponses[$sColumn . "_table"] = [
                            "title" => viewHelper::flatEllipsizeText(
                                $oSingleQuestion->questionl10ns[$this->surveyLanguage]->question,
                                true,
                                false
                            ),
                            "aSatisfactions" => $aSatisfaction,
                            "type" => "table",
                        ];
                    }
                }
            }
        }
        $this->aRenderData["aResponses"] = $aResponses;
        $aReorderSatisfactions = array();
        foreach ($aResponses as $repKey => $aResponse) {
            foreach ($aResponse['aSatisfactions'] as $iSatId => $aSatisfaction) {
                if (empty($aReorderSatisfactions[$iSatId])) {
                    $aReorderSatisfactions[$iSatId] = array(
                        'title' => $aSatisfaction['title'],
                        'aResponses' => array()
                    );
                }
                $aReorderSatisfactions[$iSatId]['aResponses'][$repKey] = $aResponses[$repKey]['aSatisfactions'][$iSatId];
                $aReorderSatisfactions[$iSatId]['aResponses'][$repKey]['title'] = $aResponses[$repKey]['title'];
                $aReorderSatisfactions[$iSatId]['aResponses'][$repKey]['type'] = isset($aResponse['type']) ? $aResponse['type'] : 'chart';
            }
        }
        $renderData["aReorderSatisfactions"] = $aReorderSatisfactions;
        $renderData["htmlComment"] = $this->get(
            "satisfactionComment",
            "Survey",
            $oSurvey->sid,
            ""
        );
        return $renderData;
    }
    /**
     * Export in CSV the dayly response rate
     */
    public function actionExportData()
    {
        if (empty($this->iSurveyId)) {
            throw new CHttpException(500);
        }
        $surveyId = $this->iSurveyId;
        $oSurvey = Survey::model()->findByPk($surveyId);
        $exportType = "dayresponse";
        $type = App()
            ->getRequest()
            ->getParam("state");
        switch ($type) {
            case "enter":
                $state = "startdate";
                break;
            case "action":
                $state = "datestamp";
                break;
            default:
                $state = "submitdate";
        }
        $aDatas = $this->getDailyResponsesRate($oSurvey->sid, $state);
        $aHeader = [gT("Day"), $this->translate("Nb")];
        header("Content-Disposition: attachment; filename=" . $state . ".csv");
        header("Content-type: text/comma-separated-values; charset=UTF-8");
        echo implode(",", $aHeader) . PHP_EOL;
        foreach ($aDatas as $key => $value) {
            echo $key . "," . $value . PHP_EOL;
        }
        die();
    }

    /**
     * Get the reponse by day
     * @param int iSurveyId : the id of the survey
     * @param string state : date to take into account
     * @return array (response by day)
     */
    private function getDailyResponsesRate($iSurveyId, $state = "submitdate")
    {
        if ($state == "submitdate") {
            $step = $this->get("step", "Survey", $iSurveyId, '');
            if ($step !== '' && $this->get("stepParticipation", "Survey", $iSurveyId, 0)) {
                return $this->getDailyResponsesRateByStep($iSurveyId);
            }
        }
        $tablename = "{{responses_{$iSurveyId}}}";
        if (App()->getConfig('versionnumber') < 7) {
            $tablename = "{{survey_{$iSurveyId}}}";
        }
        $aDailyResponsesRateArray = Yii::app()
            ->db->createCommand()
            ->select(
                "DATE({$state}) as " .
                    Yii::app()->db->quoteColumnName("date") .
                    ",COUNT(*) AS " .
                    Yii::app()->db->quoteColumnName("nb")
            )
            ->from("{$tablename} s")
            ->where("{$state} IS NOT NULL")
            ->order("date")
            ->group("date")
            ->queryAll();
        $aDailyResponsesRate = [];
        foreach ($aDailyResponsesRateArray as $aDailyResponse) {
            $aDailyResponsesRate[$aDailyResponse["date"]] =
                $aDailyResponse["nb"];
        }
        return $aDailyResponsesRate;
    }

    /**
     * Get the reponse by day
     * @param int iSurveyId : the id of the survey
     */
    private function getDailyResponsesRateByStep($iSurveyId)
    {
        $step = intval($this->get("step", "Survey", $iSurveyId, ''));
        $where = "datestamp IS NOT NULL";
        if ($step > 0) {
            $where = "datestamp IS NOT NULL and lastpage >= $step";
        }
        $tablename = "{{responses_{$iSurveyId}}}";
        if (App()->getConfig('versionnumber') < 7) {
            $tablename = "{{survey_{$iSurveyId}}}";
        }
        $aDailyResponsesRateArray = Yii::app()
            ->db->createCommand()
            ->select(
                "DATE(datestamp) as " .
                    Yii::app()->db->quoteColumnName("date") .
                    ",COUNT(*) AS " .
                    Yii::app()->db->quoteColumnName("nb")
            )
            ->from("{tablename} s")
            ->where($where)
            ->order("date")
            ->group("date")
            ->queryAll();
        $aDailyResponsesRate = [];
        foreach ($aDailyResponsesRateArray as $aDailyResponse) {
            $aDailyResponsesRate[$aDailyResponse["date"]] =
                $aDailyResponse["nb"];
        }
        return $aDailyResponsesRate;
    }

    /**
     * Get list of statictics survey for this user
     * @return void (rendering)
     */
    public function actionList()
    {
        $this->aRenderData["titre"] = gt("Surveys");
        $aStatSurveys = $this->getSurveyList();
        $aFinalSurveys = [];
        $aFooter = [
            'responsesTotal' => 0, // Total
            'responsesCount' => 0, // Submitted
            'tokensCount' => 0, // Tokens or atented
            'responsesTokenTotal' => 0,
            'responsesTokenCount' => 0,
            'rateTotal' => "",
            'rateCount' => "",
        ];
        foreach ($aStatSurveys as $aStatSurvey) {
            $aStatSurvey["responsesTotal"] = Response::model(
                $aStatSurvey["sid"]
            )->count();
            $aFooter['responsesTotal'] += $aStatSurvey["responsesTotal"];
            $step = $this->get("step", "Survey", $aStatSurvey["sid"], '');
            if ($step !== '' &&  $this->get("stepParticipation", "Survey", $aStatSurvey["sid"], 1)) {
                if (intval($step) > 0) {
                    $aStatSurvey["responsesCount"] = Response::model(
                        $aStatSurvey["sid"]
                    )->count("lastpage >= " . intval($step));
                } else {
                    $aStatSurvey["responsesCount"] = Response::model(
                        $aStatSurvey["sid"]
                    )->count();
                }
            } else {
                $aStatSurvey["responsesCount"] = Response::model(
                    $aStatSurvey["sid"]
                )->count("submitdate IS NOT NULL");
            }
            $aFooter['responsesCount'] += $aStatSurvey["responsesCount"];
            $aStatSurvey["tokensCount"] = $this->get(
                "numberMax",
                "Survey",
                $aStatSurvey["sid"],
                0
            );
            if (intval($aStatSurvey["tokensCount"]) == 0 && tableExists("{{tokens_{$aStatSurvey["sid"]}}}")) {
                $aStatSurvey["tokensCount"] = Token::model(
                    $aStatSurvey["sid"]
                )->count();
            }
            if ($aStatSurvey["tokensCount"] > 0) {
                $aStatSurvey["rateTotal"] = $aStatSurvey["responsesTotal"] / $aStatSurvey["tokensCount"];
                $aStatSurvey["rateCount"] = $aStatSurvey["responsesCount"] / $aStatSurvey["tokensCount"];
            } else {
                $aStatSurvey["rateTotal"] = "";
                $aStatSurvey["rateCount"] = "";
            }
            if (intval($aStatSurvey["responsesCount"]) > 0 || $aStatSurvey["tokensCount"] > 0) {
                $aFinalSurveys[] = $aStatSurvey;
                $aFooter['tokensCount'] += $aStatSurvey["tokensCount"];
                if ($aStatSurvey["tokensCount"] > 0) {
                    $aFooter['responsesTokenTotal'] += $aStatSurvey["responsesTotal"];
                    $aFooter['responsesTokenCount'] += $aStatSurvey["responsesCount"];
                }
            }
        }
        if ($aFooter['tokensCount'] > 0) {
            $aFooter["rateTotal"] = $aFooter["responsesTotal"] / $aFooter["tokensCount"];
            $aFooter["rateCount"] = $aFooter["responsesCount"] / $aFooter["tokensCount"];
        }
        $this->aRenderData["aSurveys"] = $aFinalSurveys;
        $this->aRenderData["surveysGrid"] = $this->renderPartial(
            'subviews.surveys_grid',
            [
                'aSurveys' => $aFinalSurveys,
                'aFooter' => $aFooter,
                'language' => $this->getRenderLanguageStrings(),
                'className' => get_class($this)
            ],
            true
        );
        $this->ownRender("list_surveys");
    }

    public function afterSuccessfulLogin()
    {
        if (!$this->get('redirectAfterLogin', null, null, true)) {
            return;
        }
        if (!$this->onlyStatAccess()) {
            return;
        }
        if ($this->isCurrentUrl) {
            return;
        }
        $returnUrl = App()->user->getReturnUrl();
        $pluginUrl = App()->createUrl("plugins/direct");
        if (empty($returnUrl) || substr($returnUrl, 0, strlen($pluginUrl)) !== $pluginUrl) {
            $url = App()->createUrl("plugins/direct", [
                "plugin" => $this->getName(),
                "function" => "list",
            ]);
            App()->user->setReturnUrl($url);
        }
    }

    /**
     * Test if have only statistics access
     * @todo : use a global settings ?
     * @return boolean
     */
    private function onlyStatAccess()
    {
        if (Yii::app() instanceof CConsoleApplication) {
            return;
        }
        if (!Yii::app()->session["loginID"]) {
            return;
        }
        $countPermission = Permission::model()->count(
            "uid=:uid AND permission NOT LIKE :permission AND entity='global' AND (create_p > 0 or read_p > 0 or update_p > 0 or delete_p > 0 or import_p > 0 or import_p > 0)",
            [":uid" => Yii::app()->session["loginID"], ":permission" => "auth%"]
        );
        $countSurveyPermission = Permission::model()->count(
            "uid=:uid AND (permission NOT LIKE :permission1 AND permission NOT LIKE :permission2) AND entity='Survey' AND (create_p > 0 or read_p > 0 or update_p > 0 or delete_p > 0 or import_p > 0 or import_p > 0)",
            [
                ":uid" => Yii::app()->session["loginID"],
                ":permission1" => "statistics",
                ":permission2" => "survey",
            ]
        );

        return !((bool) $countPermission || (bool) $countSurveyPermission);
    }
    /**
     * rendering a file in plugin view
     * @param string $type : the statitistics type, file to render (in views/subviews)
     * @return void
     */
    private function ownRender($type)
    {
        Yii::setPathOfAlias(
            "quickStatAdminParticipationAndStat",
            dirname(__FILE__)
        );
        $oEvent = $this->event;
        $this->updateTwigConfiguration();
        $this->aRenderData[
            "assetUrl"
        ] = $sAssetUrl = Yii::app()->assetManager->publish(
            dirname(__FILE__) . "/assets"
        );
        $this->aRenderData["jqplotUrl"] = Yii::app()->assetManager->publish(
            dirname(__FILE__) . "/vendor/jquery.jqplot"
        );
        $this->aRenderData["subview"] = "subviews.{$type}";
        $this->aRenderData["type"] = $type;
        $this->aRenderData["surveyList"] = $this->getSurveyList();
        $this->aRenderData["showSatisfaction"] = !empty(
            $this->get("questionNumeric", "Survey", $this->iSurveyId, [])
        );
        $this->aRenderData["showAdminSurvey"] =
            Permission::model()->hasSurveyPermission(
                $this->iSurveyId,
                "surveysettings",
                "update"
            ) && !$this->onlyStatAccess();
        $this->aRenderData["urls"] = $this->getRenderDataUrls($this->iSurveyId);
        $this->aRenderData["showExport"] = Permission::model()->hasSurveyPermission($this->iSurveyId, "response", "export");
        $this->aRenderData["showAdmin"] = !$this->onlyStatAccess();
        $this->aRenderData["className"] = self::$name;
        $this->subscribe("getPluginTwigPath", "getPluginTwigPathRender");
        if (empty($this->iSurveyId)) {
            $this->renderNoSurvey();
        }
        $surveyId = $this->iSurveyId;
        $this->aRenderData["titre"] = $this->getRenderDataTitle($surveyId);
        $twigRenderData = ["aStatPanel" => $this->aRenderData];
        $twigRenderData["aSurveyInfo"] = getSurveyInfo($surveyId, App()->getLanguage());
        $twigRenderData["aSurveyInfo"]["include_content"] = "quickstatpanel";
        $twigRenderData["aSurveyInfo"]["showprogress"] = false;
        Yii::app()->setConfig("surveyID", $surveyId);
        $twigRenderData["aSurveyInfo"]["alanguageChanger"]["show"] = false;
        $alanguageChangerDatas = getLanguageChangerDatas(App()->language);
        if ($alanguageChangerDatas) {
            $twigRenderData["aSurveyInfo"]["alanguageChanger"]["show"] = true;
            $twigRenderData["aSurveyInfo"]["alanguageChanger"][
                "datas"
            ] = $alanguageChangerDatas;
        }
        $twigRenderData["aStatPanel"]["userName"] = Permission::model()->getUserId() ? Yii::app()->user->getName() : null;
        $twigRenderData["aStatPanel"]["surveyUrl"] = App()->createUrl(
            "plugins/direct",
            [
                "plugin" => $this->getName(),
                "function" => "stat",
                "sid" => $surveyId,
            ]
        );
        $twigRenderData["aStatPanel"]["urls"] = $this->getRenderDataUrls($surveyId);
        $twigRenderData["aStatPanel"]["language"] = $this->getRenderLanguageStrings();
        $languageData = getLanguageDetails(App()->getLanguage());
        $twigRenderData["aStatPanel"]["jqplotDateFormat"] = $this->getJqplotDateFormat($languageData['dateformat']);

        App()->clientScript->registerScriptFile(
            Yii::app()->getConfig("generalscripts") . "nojs.js",
            CClientScript::POS_HEAD
        );
        Template::model()->getInstance(null, $surveyId);
        Yii::app()->twigRenderer->renderTemplateFromFile(
            "layout_global.twig",
            $twigRenderData,
            false
        );
        Yii::app()->end();
    }

    /**
     * Render the list of available survey
     * @return void
     */
    private function renderNoSurvey()
    {
        $lang = Yii::app()->language;
        $aLanguages = getLanguageDataRestricted(false, "short");
        if (!isset($aLanguages[$lang])) {
            $lang = App()->getConfig("defaultlang");
            Yii::app()->language = $lang;
        }
        $oTemplate = Template::model()->getInstance(
            App()->getConfig("defaulttheme")
        );
        $twigRenderData = ["aStatPanel" => $this->aRenderData];
        $twigRenderData["aSurveyInfo"] = [
            "oTemplate" => $oTemplate,
            "sSiteName" => Yii::app()->getConfig("sitename"),
            "sSiteAdminName" => Yii::app()->getConfig("siteadminname"),
            "sSiteAdminEmail" => Yii::app()->getConfig("siteadminemail"),
            "bShowClearAll" => false,
            "surveyls_title" => Yii::app()->getConfig("sitename"),
        ];
        $twigRenderData["aSurveyInfo"]["include_content"] = "quickstatpanel";
        $twigRenderData["aSurveyInfo"]["showprogress"] = false;
        $twigRenderData["aSurveyInfo"]["active"] = true;
        $twigRenderData["aStatPanel"]["userName"] = Yii::app()->user->getName();
        $twigRenderData["aStatPanel"]["surveyUrl"] = App()->createUrl(
            "plugins/direct",
            ["plugin" => $this->getName(), "function" => "stat"]
        );
        Yii::app()->clientScript->registerScriptFile(
            Yii::app()->getConfig("generalscripts") . "nojs.js",
            CClientScript::POS_HEAD
        );
        Yii::app()->twigRenderer->renderTemplateFromFile(
            "layout_global.twig",
            $twigRenderData,
            false
        );
        Yii::app()->end();
    }

    public function getPluginTwigPath()
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        $viewPath = dirname(__FILE__) . "/twig";
        if (intval(App()->getConfig('versionnumber') < 6)) {
            $viewPath = dirname(__FILE__) . "/legacy/twig";
        }
        $this->getEvent()->append("add", [$viewPath]);
    }

    public function getPluginTwigPathRender()
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        $this->getPluginTwigPath();
        $forcedPath = dirname(__FILE__) . "/twig_replace";
        if (intval(App()->getConfig('versionnumber') < 6)) {
            $forcedPath = dirname(__FILE__) . "/legacy/twig";
        }
        $this->getEvent()->append("replace", [$forcedPath]);
    }

    public function getValidScreenFiles()
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        $this->subscribe("getPluginTwigPath");
        if (
            $this->getEvent()->get("type") != "view" ||
            ($this->getEvent()->get("screen") &&
                $this->getEvent()->get("screen") != "welcome")
        ) {
            return;
        }
        $this->getEvent()->append("add", [
            "subviews/quickstatpanel/statpanel_about.twig",
            "subviews/quickstatpanel/statpanel_usermenu.twig",
            "subviews/quickstatpanel/statpanel_param.twig",
        ]);
    }

    /**
     * Add needed function to twig
     */
    private function updateTwigConfiguration()
    {
        $twigRenderer = App()->twigRenderer;
        $twigRenderer->addFilters(
            [
                'number_format',
                'date_format'
            ]
        );
    }

    /**
     * Return the survey with allowed access
     */
    private function getSurveyList()
    {
        if (!Yii::app()->user->getId()) {
            return [];
        }
        static $aStatSurveys;
        if (null !== $aStatSurveys) {
            return $aStatSurveys;
        }
        $oCriteria = new CdbCriteria();
        $oCriteria->select = ['sid', 'active', 'language'];
        $oCriteria->with = ['languagesettings' => [
                'select' => 'surveyls_title',
                'where' => 't.language = languagesettings.language'
            ]
        ];
        $oCriteria->condition = "active=:active";
        $oCriteria->params[":active"] = "Y";
        if (!Permission::model()->hasGlobalPermission("surveys", "read")) {
            $oCriteria->addCondition(
                "sid IN (SELECT entity_id FROM {{permissions}} WHERE entity = :entity AND  uid = :uid AND permission = :permission AND read_p = 1)"
            );
            $oCriteria->params[":entity"] = "Survey";
            $oCriteria->params[":uid"] = Yii::app()->user->getId();
            $oCriteria->params[":permission"] = "statistics";
            $oCriteria->compare(
                "owner_id",
                Yii::app()->user->getId(),
                false,
                "OR"
            );
        }
        $aSurveys = Survey::model()
            ->findAll($oCriteria);
        $aStatSurveys = [];
        if (!empty($aSurveys)) {
            foreach ($aSurveys as $oSurvey) {
                if (self::isSurveyActive($oSurvey->sid)) {
                    $title = $this->get(
                        "alternateTitle",
                        "Survey",
                        $oSurvey->sid,
                        ""
                    );
                    if (empty($title)) {
                        $title = $oSurvey->defaultlanguage->surveyls_title;
                    }
                    $aStatSurveys[] = array_merge(
                        $oSurvey->attributes,
                        $oSurvey->defaultlanguage->attributes,
                        ["title" => $title]
                    );
                }
            }
        }
        return $aStatSurveys;
    }

    /**
     * Update plugin settings for the link and lang
     */
    public function getPluginSettings($getValues = true)
    {
        $url = $this->api->createUrl("plugins/direct", [
            "plugin" => $this->getName(),
            "function" => "list",
        ]);
        $this->settings["docu"]["content"] =
            "<p class='alert alert-info'>" .
            $this->translate("The link to the statistics is:") .
            "<a href='{$url}'>{$url}</a></p>";
        $this->settings["dailyRateEnterAllow"]["label"] = $this->translate(
            "Activate daily participation"
        );
        $this->settings["dailyRateEnterAllow"]["help"] = $this->translate(
            "This allow to activate daily participation by survey"
        );
        $this->settings["dailyRateActionAllow"]["label"] = $this->translate(
            "Activate daily action"
        );
        $this->settings["dailyRateActionAllow"]["help"] = $this->translate(
            "This allow to activate daily action by survey"
        );
        return parent::getPluginSettings($getValues);
    }

    /**
     * Get the lanuage strings for twig render
     * @return string[] : string in english => translated string
     */
    private function getRenderLanguageStrings()
    {
        return array(
            "Participation" => $this->translate("Participation"),
            "Satisfaction" => $this->translate("Satisfaction"),
            "Administration" => $this->translate("Administration"),
            "Population" => $this->translate("Population"),
            "Export" => $this->translate("Export"),
            "Daily participation" => $this->translate("Daily participation"),
            "Daily participation (cumulative)" => $this->translate("Daily participation (cumulative)"),
            "Number of connections" => $this->translate("Number of connections"),
            "Daily participation rate" => $this->translate("Daily participation rate"),
            "Expected participants" => $this->translate("Expected participants"),
            "Invitation sent" => $this->translate("Invitation sent"),
            "Responses" => $this->translate("Responses"),
            "Globally" => $this->translate("Globally"),
            "Participation rate" => $this->translate("Participation rate"),
            'Total' => $this->translate("Total")
        );
    }

    /**
     * Get the moyenne for a numeric question type
     * @param : $sColumn : column title
     * @return float|false
     */
    private function getAverage($sColumn, $aConditions = null)
    {
        $tablename = "{{responses_{$this->iSurveyId}}}";
        if (App()->getConfig('versionnumber') < 7) {
            $tablename = "{{survey_{$this->iSurveyId}}}";
        }
        $step = $this->get("step", "Survey", $this->iSurveyId, '');
        $sQuotedColumn = Yii::app()->db->quoteColumnName($sColumn);
        $iTotal = $this->getCountNumeric($sColumn, $aConditions);
        if ($iTotal <= 0) {
            $average = false;
            return $average;
        }
        $oCriteria = new CDbCriteria();
        $oCriteria->select = "SUM({$sQuotedColumn}) as SUM";
        if ($step === '') {
            $oCriteria->condition = "submitdate IS NOT NULL";
        } elseif (intval($step) > 0) {
            $oCriteria->condition = "lastpage >= " . intval($step);
        }
        $oCriteria->addCondition(
            "concat('',{$sQuotedColumn} * 1) = {$sQuotedColumn}"
        );
        if (empty($aConditions)) {
            $iSum = (int) Yii::app()
                ->db->getCommandBuilder()
                ->createFindCommand(
                    SurveyDynamic::model($this->iSurveyId)->getTableSchema(),
                    $oCriteria
                )
                ->queryScalar();
        } else {
            foreach ($aConditions as $column => $values) {
                if (is_array($values)) {
                    $oCriteria->addInCondition($column, $values);
                } else {
                    $oCriteria->compare($column, $values);
                }
            }
            if (tableExists("{{tokens_{$this->iSurveyId}}}")) {
                /* Manually construct where command ... */
                $step = $this->get("step", "Survey", $this->iSurveyId, '');
                $sWhere = "";
                if ($step === '' || !$this->get("step", "Survey", $this->iSurveyId, 0)) {
                    $sWhere = "submitdate IS NOT NULL";
                } elseif (intval($step) > 0) {
                    $sWhere = "lastpage >= " . intval($step);
                }
                $sWhere .= " AND concat('',{$sQuotedColumn} * 1) = {$sQuotedColumn}";
                $params = [];
                $countParams = 1;
                foreach ($aConditions as $column => $values) {
                    if (is_array($values)) {
                        $valParams = [];
                        foreach ($values as $value) {
                            $valParams[] = ":p{$countParams}";
                            $params[":p{$countParams}"] = $value;
                            $countParams++;
                        }
                        $sWhere .=
                            " AND " .
                            Yii::app()->db->quoteColumnName($column) .
                            " IN (" .
                            implode(",", $valParams) .
                            ")";
                    } else {
                        $params[":p{$countParams}"] = $values;
                        $sWhere .=
                            " AND " .
                            Yii::app()->db->quoteColumnName($column) .
                            "= :p{$countParams}";
                        $countParams++;
                    }
                }
                $iSum = (int) Yii::app()
                    ->db->createCommand()
                    ->select("SUM({$sQuotedColumn}) as SUM")
                    ->from("{$tablename} s")
                    ->join("{{tokens_{$this->iSurveyId}}} t", "s.token=t.token")
                    ->where($sWhere, $params)
                    ->queryScalar();
            } else {
                $iSum = (int) Yii::app()
                    ->db->getCommandBuilder()
                    ->createFindCommand(
                        SurveyDynamic::model(
                            $this->iSurveyId
                        )->getTableSchema(),
                        $oCriteria
                    )
                    ->queryScalar();
            }
        }
        if ($iTotal > 0) {
            $average = $iSum / $iTotal;
        } else {
            $average = false;
        }
        return $average;
    }
    /**
     * Get the maximum numeric value question type
     * @todo fix it, control it
     * @param : $sColumn : column title
     * @return float|false
     */
    private function getMax($sColumn, $aCondition = null)
    {
        $aMax = []; // Go to cache ?
        //~ if(isset($aMax[$sColumn]))
        //~ return $aMax[$sColumn];
        $step = $this->get("step", "Survey", $this->iSurveyId, '');
        $sQuotedColumn = Yii::app()->db->quoteColumnName($sColumn);
        $oCriteria = new CDbCriteria();
        $oCriteria->select = "MAX({$sQuotedColumn})";
        if ($step === '') {
            $oCriteria->condition = "submitdate IS NOT NULL";
        } elseif (intval($step) > 0) {
            $oCriteria->condition = "lastpage >= " . intval($step);
        }
        $oCriteria->addCondition(
            "concat('',{$sQuotedColumn} * 1) = {$sQuotedColumn}"
        );
        $iMax = Yii::app()
            ->db->getCommandBuilder()
            ->createFindCommand(
                SurveyDynamic::model($this->iSurveyId)->getTableSchema(),
                $oCriteria
            )
            ->queryScalar();
        if ($iMax > 0) {
            $aMax[$sColumn] = $iMax;
        } else {
            $aMax[$sColumn] = false;
        }
        return $aMax[$sColumn];
    }
    /**
     * Get the count of answered for a numeric question type (only numeric answers)
     * @param string $sColumn : column title
     * @param array $aCondition
     * @return integer
     */
    private function getCountNumeric($sColumn, $aConditions = null)
    {
        //~ $aCountNumeric=array(); // Go to cache ?
        //~ if(isset($aCountNumeric[$sColumn]))
        //~ return $aCountNumeric[$sColumn];
        $step = $this->get("step", "Survey", $this->iSurveyId, '');
        $sQuotedColumn = Yii::app()->db->quoteColumnName($sColumn);
        $oCriteria = new CDbCriteria();
        if ($step === '') {
            $oCriteria->condition = "submitdate IS NOT NULL";
        } elseif (intval($step) > 0) {
            $oCriteria->condition = "lastpage >= " . intval($step);
        }
        $oCriteria->addCondition(
            "concat('',{$sQuotedColumn} * 1) = {$sQuotedColumn}"
        );
        if (empty($aConditions)) {
            $iCountNumeric = (int) Response::model($this->iSurveyId)->count(
                $oCriteria
            );
        } else {
            foreach ($aConditions as $column => $values) {
                if (is_array($values)) {
                    $oCriteria->addInCondition($column, $values);
                } else {
                    $oCriteria->compare($column, $values);
                }
            }
            if (tableExists("{{tokens_{$this->iSurveyId}}}")) {
                $iCountNumeric = (int) Response::model($this->iSurveyId)
                    ->with("token")
                    ->count($oCriteria);
            } else {
                $iCountNumeric = (int) Response::model($this->iSurveyId)->count(
                    $oCriteria
                );
            }
        }
        return $iCountNumeric;
    }

    /**
     * get the array of token values
     * @param string attribute to take (column name)
     */
    private function getTokenValues($attribute)
    {
        $oTokenValues = Token::model($this->iSurveyId)->findAll([
            "select" => $attribute,
            "condition" => "$attribute !='' and $attribute is not null",
            "group" => $attribute,
            "order" => $attribute,
            "distinct" => true,
        ]);
        if ($oTokenValues) {
            $aTokenValues = CHtml::listData(
                $oTokenValues,
                $attribute,
                $attribute
            );
            foreach ($this->aPushTokenValue as $sPushToken) {
                if (array_key_exists($sPushToken, $aTokenValues)) {
                    unset($aTokenValues[$sPushToken]);
                    $aTokenValues[$sPushToken] = $sPushToken;
                }
            }
            return $aTokenValues;
        }
    }

    /**
     * Check validated (with permission) surveyId
     * @param integer
     * @throw exception
     * @return boolean
     **/
    public function validateSurveyId($surveyId)
    {
        $oSurvey = Survey::model()->findByPk($surveyId);
        if (!$oSurvey || !$oSurvey->isActive) {
            throw new CHttpException(404, gT("The survey in which you are trying to participate does not seem to exist."));
        }
        if (!Permission::model()->hasSurveyPermission($surveyId, "statistics") && !$this->checkAccesskey($surveyId)) {
            if (Permission::model()->getUserId()) {
                throw new CHttpException(403, gT("We are sorry but you don't have permissions to do this.", 'unescaped'));
            }
            App()->user->setReturnUrl(App()->request->requestUri);
            App()->controller->redirect(["/admin/authentication"]);
        }
    }

    /**
     * Set the base language according to param and/or admin user language
     * @param integer|null $surveyId
     * @return void
     */
    private function setBaseLanguage($surveyId)
    {
        if (App()->getRequest()->getParam("lang")) {
            App()->language = App()->getRequest()->getParam("lang");
        } elseif (Yii::app()->session["statlanguage"]) {
            App()->language = Yii::app()->session["statlanguage"];
        }
        if (!$surveyId) {
            $aLanguages = getLanguageDataRestricted(false, "short");
            if (!isset($aLanguages[Yii::app()->language])) {
                $defaultlang = App()->getConfig("defaultlang");
                Yii::app()->language = $defaultlang;
            }
        } elseif (!in_array(Yii::app()->language, Survey::model()->findByPk($surveyId)->getAllLanguages())) {
            Yii::app()->language = Survey::model()->findByPk($surveyId)->language;
        }
        $this->surveyLanguage = Yii::app()->session["statlanguage"] = Yii::app()->language;
    }

    /**
     * Set survey
     * @param \Survey
     * @throw CHttpException
     * @return void
     */
    private function setSurvey($oSurvey)
    {
        $this->iSurveyId = $oSurvey->sid;
        $this->surveyLanguage = $oSurvey->language;
        if (in_array(App()->language, $oSurvey->getAllLanguages())) {
            $this->surveyLanguage = App()->language;
        }
    }

    /**
     * return the render data urls with needed params
     * @param integer $surveyId
     * @return string[];
     */
    private function getRenderDataUrls($surveyId)
    {
        $params = [
            'plugin' => 'quickStatAdminParticipationAndStat',
            'sid' => $surveyId,
            'lang' => App()->language
        ];
        if ($accesskey = App()->getRequest()->getQuery('accesskey', '')) {
            $params['accesskey'] = $accesskey;
        }
        return [
            'participation' => App()->createUrl("plugins/direct", array_merge($params, ['function' => 'participation'])),
            'satisfaction' => App()->createUrl("plugins/direct", array_merge($params, ['function' => 'satisfaction'])),
            'administration' => App()->createUrl(
                "admin/pluginhelper",
                [
                    'sa' => 'sidebody',
                    'plugin' => 'quickStatAdminParticipationAndStat',
                    'method' => 'actionSettings',
                    'surveyId' => $surveyId,
                ]
            ),
        ];
    }

    /**
     * return the title for current language
     * @param integer $surveyId
     * @return string
     **/
    private function getRenderDataTitle($surveyId)
    {
        $title = $this->get("alternateTitle", "Survey", $surveyId, "");
        if ($title === "") {
            $oSurvey = Survey::model()->with("languagesettings")->find("sid=:sid", [":sid" => $surveyId]);
            $title = $oSurvey->getLocalizedTitle();
        }
        return $title;
    }

    /**
     * Check if there are an access key in params
     * @param integer $surveyId
     * return boolean
     */
    private function checkAccesskey($surveyId)
    {
        $allowedAccessKey =  preg_replace('/[^A-Za-z0-9._~-]/', '', $this->get("accessKey", "Survey", $surveyId, ""));
        if (empty($allowedAccessKey)) {
            return false;
        }
        return App()->getRequest()->getParam('accesskey', '') === $allowedAccessKey;
    }

    /**
     * Translate a plugin string
     * @param string $string to translate
     * @return string
     */
    private function translate($string)
    {
        return $this->gT($string, 'unescaped');
    }

    /**
     * getDateformat from number to jqplot formatString
     * @see surveytranslator_helper/ getDateFormatData
     * @var integer getLanguageData[lang][dateformat]
     * @return string
     **/
    private function getJqplotDateFormat($iDateFormat)
    {
        // see https://web.archive.org/web/20230327045702/http://www.jqplot.com/docs/files/plugins/jqplot-dateAxisRenderer-js.html
        $aDateFormats = array(
            1 => '%d.%m.%Y',
            2 => '%d-%m-%Y',
            3 => '%Y.%m.%d',
            4 => '%#d.%#m.%Y',
            5 => '%d/%m/%Y',
            6 => '%Y-%m-%d',
            7 => '%Y/%m/%d',
            8 => '%#d/%#m/%Y',
            9 => '%m-%d-%Y',
            10 => '%m.%d.%Y',
            11 => '%m/%d/%Y',
            12 => '%#d-%#m-%Y'
        );
        if (isset($aDateFormats[$iDateFormat])) {
            return $aDateFormats[$iDateFormat];
        }
        return $aDateFormats[6];
    }

    /**
     * Get if survey if active, checking response table
     * @param integre $sid
     * @return boolean
     */
    private static function isSurveyActive($sid)
    {
        if (App()->getConfig('versionnumber') < 7) {
            return tableExists("{{survey_{$sid}}}");
        }
        return tableExists("{{responses_{$sid}}}");
    }
}
