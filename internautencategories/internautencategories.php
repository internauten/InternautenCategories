<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class InternautenCategories extends Module
{
    private const CONFIG_CATEGORY_ID = 'IC_SORT_PARENT_CATEGORY_ID';
    private const CONFIG_LANGUAGE_ID = 'IC_SORT_PRIMARY_LANGUAGE_ID';
    private const CONFIG_SORT_ALL_LANGUAGES = 'IC_SORT_ALL_LANGUAGES';
    private const CONFIG_SORT_LOCALE = 'IC_SORT_LOCALE';
    private const CONFIG_BATCH_SIZE = 'IC_SORT_UPDATE_BATCH_SIZE';
    private const DEFAULT_BATCH_SIZE = 200;
    private const MIN_BATCH_SIZE = 10;
    private const MAX_BATCH_SIZE = 2000;

    public function __construct()
    {
        $this->name = 'internautencategories';
        $this->tab = 'administration';
        $this->version = '0.0.1';
        $this->author = 'die.internauten.ch';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Internauten Categories');
        $this->description = $this->l('Sorts subcategories alphabetically within a category by updating their position.');
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        ];
    }

    public function install()
    {
        $context = Context::getContext();
        $defaultLanguageId = (int) $context->language->id;

        return parent::install()
            && Configuration::updateValue(self::CONFIG_CATEGORY_ID, '')
            && Configuration::updateValue(self::CONFIG_LANGUAGE_ID, $defaultLanguageId)
            && Configuration::updateValue(self::CONFIG_SORT_ALL_LANGUAGES, 1)
            && Configuration::updateValue(self::CONFIG_SORT_LOCALE, $this->getLanguageLocale($defaultLanguageId))
            && Configuration::updateValue(self::CONFIG_BATCH_SIZE, self::DEFAULT_BATCH_SIZE);
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::CONFIG_CATEGORY_ID);
        Configuration::deleteByName(self::CONFIG_LANGUAGE_ID);
        Configuration::deleteByName(self::CONFIG_SORT_ALL_LANGUAGES);
        Configuration::deleteByName(self::CONFIG_SORT_LOCALE);
        Configuration::deleteByName(self::CONFIG_BATCH_SIZE);

        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitInternautenCategoriesConfig')) {
            $output .= $this->saveConfiguration();
        }

        if (Tools::isSubmit('submitInternautenCategoriesSort')) {
            $result = $this->runSorting();
            if ($result['ok']) {
                $output .= $this->displayConfirmation(sprintf(
                    $this->l('Sorting completed. Parent categories processed: %d. Subcategories reordered: %d.'),
                    $result['parents'],
                    $result['children']
                ));
            } else {
                $output .= $this->displayError($result['error']);
            }
        }

        return $output . $this->renderForm();
    }

    private function saveConfiguration()
    {
        $categoryIdRaw = trim((string) Tools::getValue(self::CONFIG_CATEGORY_ID, ''));
        $languageId = (int) Tools::getValue(self::CONFIG_LANGUAGE_ID, (int) $this->context->language->id);
        $sortAllLanguages = (int) Tools::getValue(self::CONFIG_SORT_ALL_LANGUAGES, 1);
        $sortLocale = trim((string) Tools::getValue(self::CONFIG_SORT_LOCALE, ''));
        $batchSizeRaw = trim((string) Tools::getValue(self::CONFIG_BATCH_SIZE, (string) self::DEFAULT_BATCH_SIZE));

        if ($categoryIdRaw !== '' && (!ctype_digit($categoryIdRaw) || (int) $categoryIdRaw <= 0)) {
            return $this->displayError($this->l('Category ID must be a positive integer or empty.'));
        }

        if ($languageId <= 0 || !Language::getLanguage($languageId)) {
            return $this->displayError($this->l('Please select a valid language.'));
        }

        if ($sortLocale === '') {
            return $this->displayError($this->l('Please provide a valid locale, for example de_DE or en_US.'));
        }

        if (!ctype_digit($batchSizeRaw)) {
            return $this->displayError(sprintf(
                $this->l('Batch size must be an integer between %d and %d.'),
                self::MIN_BATCH_SIZE,
                self::MAX_BATCH_SIZE
            ));
        }

        $batchSize = (int) $batchSizeRaw;
        if ($batchSize < self::MIN_BATCH_SIZE || $batchSize > self::MAX_BATCH_SIZE) {
            return $this->displayError(sprintf(
                $this->l('Batch size must be between %d and %d.'),
                self::MIN_BATCH_SIZE,
                self::MAX_BATCH_SIZE
            ));
        }

        Configuration::updateValue(self::CONFIG_CATEGORY_ID, $categoryIdRaw);
        Configuration::updateValue(self::CONFIG_LANGUAGE_ID, $languageId);
        Configuration::updateValue(self::CONFIG_SORT_ALL_LANGUAGES, $sortAllLanguages === 1 ? 1 : 0);
        Configuration::updateValue(self::CONFIG_SORT_LOCALE, $sortLocale);
        Configuration::updateValue(self::CONFIG_BATCH_SIZE, $batchSize);

        return $this->displayConfirmation($this->l('Settings updated.'));
    }

    private function runSorting()
    {
        $shopId = (int) $this->context->shop->id;
        $languageId = (int) Configuration::get(self::CONFIG_LANGUAGE_ID);
        $configuredParent = trim((string) Configuration::get(self::CONFIG_CATEGORY_ID));
        $sortAllLanguages = (int) Configuration::get(self::CONFIG_SORT_ALL_LANGUAGES) === 1;
        $locale = trim((string) Configuration::get(self::CONFIG_SORT_LOCALE));
        $batchSize = $this->getConfiguredBatchSize();

        if ($languageId <= 0 || !Language::getLanguage($languageId)) {
            return [
                'ok' => false,
                'error' => $this->l('Invalid language configured. Please save module settings first.'),
            ];
        }

        if ($locale === '') {
            return [
                'ok' => false,
                'error' => $this->l('Invalid locale configured. Please save module settings first.'),
            ];
        }

        $languageOrder = $this->getLanguageOrder($languageId, $sortAllLanguages);
        if (empty($languageOrder)) {
            return [
                'ok' => false,
                'error' => $this->l('No active languages found for sorting.'),
            ];
        }

        $parentCategoryIds = [];
        if ($configuredParent !== '') {
            $parentId = (int) $configuredParent;
            if ($parentId <= 0 || !Category::categoryExists($parentId, $this->context->shop->id)) {
                return [
                    'ok' => false,
                    'error' => $this->l('Configured parent category does not exist in this shop.'),
                ];
            }
            $parentCategoryIds = [$parentId];
        } else {
            $parentCategoryIds = $this->getParentCategoriesWithChildren($shopId);
        }

        $processedParents = 0;
        $reorderedChildren = 0;

        foreach ($parentCategoryIds as $parentId) {
            $children = $this->getChildCategoriesWithNames((int) $parentId, $languageOrder, $shopId);
            if (count($children) < 2) {
                continue;
            }

            usort($children, function (array $a, array $b) use ($languageOrder, $locale) {
                return $this->compareChildren($a, $b, $languageOrder, $locale);
            });

            $updateResult = $this->applySortedPositionsInBatches($children, $shopId, (int) $parentId, $batchSize);
            if (!$updateResult['ok']) {
                return [
                    'ok' => false,
                    'error' => $updateResult['error'],
                ];
            }

            ++$processedParents;
            $reorderedChildren += count($children);
        }

        return [
            'ok' => true,
            'parents' => $processedParents,
            'children' => $reorderedChildren,
        ];
    }

    private function getParentCategoriesWithChildren($shopId)
    {
        $sql = 'SELECT DISTINCT c.id_parent
                FROM `' . _DB_PREFIX_ . 'category` c
                INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                    ON cs.id_category = c.id_category
                    AND cs.id_shop = ' . (int) $shopId . '
                WHERE c.id_parent > 0
                ORDER BY c.id_parent ASC';

        $rows = Db::getInstance()->executeS($sql);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static function ($row) {
            return (int) $row['id_parent'];
        }, $rows);
    }

    private function getChildCategoriesWithNames($parentId, array $languageIds, $shopId)
    {
        $languageIds = array_map('intval', $languageIds);
        if (empty($languageIds)) {
            return [];
        }

        $sql = 'SELECT c.id_category, cl.id_lang, cl.name
                FROM `' . _DB_PREFIX_ . 'category` c
                INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                    ON cs.id_category = c.id_category
                    AND cs.id_shop = ' . (int) $shopId . '
                INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                    ON cl.id_category = c.id_category
                    AND cl.id_lang IN (' . implode(',', $languageIds) . ')
                    AND cl.id_shop = ' . (int) $shopId . '
                WHERE c.id_parent = ' . (int) $parentId . '
                ORDER BY c.id_category ASC';

        $rows = Db::getInstance()->executeS($sql);

        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $children = [];
        foreach ($rows as $row) {
            $childId = (int) $row['id_category'];
            if (!isset($children[$childId])) {
                $children[$childId] = [
                    'id_category' => $childId,
                    'names' => [],
                ];
            }

            $children[$childId]['names'][(int) $row['id_lang']] = (string) $row['name'];
        }

        return array_values($children);
    }

    private function getLanguageOrder($primaryLanguageId, $sortAllLanguages)
    {
        $languages = Language::getLanguages(true, (int) $this->context->shop->id, false);
        $languageIds = [];

        foreach ($languages as $language) {
            $languageIds[] = (int) $language['id_lang'];
        }

        if (!$sortAllLanguages) {
            return [$primaryLanguageId];
        }

        $languageIds = array_values(array_unique($languageIds));
        usort($languageIds, static function ($a, $b) {
            return $a <=> $b;
        });

        $languageIds = array_values(array_filter($languageIds, static function ($id) use ($primaryLanguageId) {
            return (int) $id !== (int) $primaryLanguageId;
        }));

        array_unshift($languageIds, (int) $primaryLanguageId);

        return array_values(array_unique($languageIds));
    }

    private function compareChildren(array $left, array $right, array $languageOrder, $locale)
    {
        foreach ($languageOrder as $languageId) {
            $leftName = $this->getNameForLanguage($left, (int) $languageId);
            $rightName = $this->getNameForLanguage($right, (int) $languageId);

            $result = $this->compareStringsByLocale($leftName, $rightName, $locale);
            if ($result !== 0) {
                return $result;
            }
        }

        return (int) $left['id_category'] <=> (int) $right['id_category'];
    }

    private function getNameForLanguage(array $child, $languageId)
    {
        if (isset($child['names'][$languageId]) && trim((string) $child['names'][$languageId]) !== '') {
            return (string) $child['names'][$languageId];
        }

        if (!empty($child['names']) && is_array($child['names'])) {
            foreach ($child['names'] as $name) {
                if (trim((string) $name) !== '') {
                    return (string) $name;
                }
            }
        }

        return '';
    }

    private function compareStringsByLocale($left, $right, $locale)
    {
        $left = (string) $left;
        $right = (string) $right;

        if (class_exists('Collator')) {
            static $collatorCache = [];

            if (!isset($collatorCache[$locale])) {
                $collator = new Collator($locale);
                if ($collator !== null) {
                    // Tertiary keeps umlaut/accent distinctions and deterministic case handling.
                    $collator->setStrength(Collator::TERTIARY);
                }
                $collatorCache[$locale] = $collator;
            }

            if ($collatorCache[$locale] instanceof Collator) {
                return (int) $collatorCache[$locale]->compare($left, $right);
            }
        }

        return strcmp(Tools::strtolower($left), Tools::strtolower($right));
    }

    private function getLanguageLocale($languageId)
    {
        $language = Language::getLanguage((int) $languageId);
        if (!is_array($language)) {
            return 'de_DE';
        }

        if (!empty($language['locale'])) {
            return (string) $language['locale'];
        }

        if (!empty($language['language_code'])) {
            return str_replace('-', '_', (string) $language['language_code']);
        }

        if (!empty($language['iso_code'])) {
            return strtolower((string) $language['iso_code']) . '_' . strtoupper((string) $language['iso_code']);
        }

        return 'de_DE';
    }

    private function applySortedPositionsInBatches(array $children, $shopId, $parentId, $batchSize)
    {
        $positionsByCategoryId = [];
        foreach ($children as $position => $child) {
            $positionsByCategoryId[(int) $child['id_category']] = (int) $position;
        }

        if (empty($positionsByCategoryId)) {
            return ['ok' => true];
        }

        $batchSize = (int) $batchSize;
        if ($batchSize < self::MIN_BATCH_SIZE || $batchSize > self::MAX_BATCH_SIZE) {
            $batchSize = self::DEFAULT_BATCH_SIZE;
        }

        $db = Db::getInstance();
        $chunks = array_chunk($positionsByCategoryId, $batchSize, true);

        foreach ($chunks as $chunk) {
            $db->execute('START TRANSACTION');

            $categoryShopUpdated = $this->updatePositionsBatch('category_shop', $chunk, (int) $shopId, true);
            $categoryUpdated = $this->updatePositionsBatch('category', $chunk, (int) $shopId, false);

            if (!$categoryShopUpdated || !$categoryUpdated) {
                $db->execute('ROLLBACK');

                return [
                    'ok' => false,
                    'error' => sprintf(
                        $this->l('Batch update failed for parent category ID %d. No changes from the current batch were committed.'),
                        (int) $parentId
                    ),
                ];
            }

            $db->execute('COMMIT');
        }

        return ['ok' => true];
    }

    private function updatePositionsBatch($table, array $positionsByCategoryId, $shopId, $withShopCondition)
    {
        if (empty($positionsByCategoryId)) {
            return true;
        }

        $caseParts = [];
        $ids = [];

        foreach ($positionsByCategoryId as $categoryId => $position) {
            $categoryId = (int) $categoryId;
            $position = (int) $position;
            $ids[] = $categoryId;
            $caseParts[] = 'WHEN ' . $categoryId . ' THEN ' . $position;
        }

        $sql = 'UPDATE `' . _DB_PREFIX_ . bqSQL($table) . '`
                SET `position` = CASE `id_category` ' . implode(' ', $caseParts) . ' END
                WHERE `id_category` IN (' . implode(',', $ids) . ')';

        if ($withShopCondition) {
            $sql .= ' AND `id_shop` = ' . (int) $shopId;
        }

        return (bool) Db::getInstance()->execute($sql);
    }

    private function getConfiguredBatchSize()
    {
        $value = (int) Configuration::get(self::CONFIG_BATCH_SIZE);

        if ($value < self::MIN_BATCH_SIZE || $value > self::MAX_BATCH_SIZE) {
            return self::DEFAULT_BATCH_SIZE;
        }

        return $value;
    }

    private function getDynamicBatchRecommendationText()
    {
        $shopId = (int) $this->context->shop->id;
        $configuredParentRaw = trim((string) Tools::getValue(
            self::CONFIG_CATEGORY_ID,
            (string) Configuration::get(self::CONFIG_CATEGORY_ID)
        ));

        $targetParentId = null;
        if ($configuredParentRaw !== '' && ctype_digit($configuredParentRaw) && (int) $configuredParentRaw > 0) {
            $targetParentId = (int) $configuredParentRaw;
        }

        $estimatedChildren = $this->getEstimatedChildCountForRecommendation($shopId, $targetParentId);

        if ($estimatedChildren <= 0) {
            return $this->l('No matching subcategories detected yet. Default batch size 200 is a safe start.');
        }

        $recommendation = $this->getRecommendedBatchRange($estimatedChildren);
        $severityLabel = $this->l($recommendation['severity_label']);
        $severityColor = $recommendation['severity_color'];

        $plainText = sprintf(
            $this->l('Auto recommendation based on detected subcategories (%d): %d-%d. Load level: %s.'),
            (int) $estimatedChildren,
            (int) $recommendation['min'],
            (int) $recommendation['max'],
            $severityLabel
        );

        // HelperForm descriptions support HTML in most PrestaShop versions.
        $tooltipHtml = $this->getRecommendationThresholdTooltipHtml();

        $htmlText = sprintf(
            '%s <span style="display:inline-block;margin-left:6px;padding:1px 6px;border-radius:3px;background:%s;color:#fff;font-weight:600;">%s</span>',
            Tools::safeOutput($plainText),
            Tools::safeOutput($severityColor),
            Tools::safeOutput($severityLabel)
        );

        return $htmlText . ' ' . $tooltipHtml;
    }

    private function getRecommendationThresholdTooltipHtml()
    {
        $tooltipText = $this->l('Thresholds: <=200 => LOW, <=1000 => MEDIUM, <=3000 => HIGH, >3000 => VERY HIGH.');

        return sprintf(
            '<span title="%s" style="display:inline-block;margin-left:6px;min-width:18px;height:18px;padding:0 5px;border:1px solid #1f2d3d;border-radius:999px;font-size:12px;line-height:16px;cursor:help;color:#1f2d3d;background:#f4f6f9;font-weight:700;text-align:center;vertical-align:middle;box-shadow:0 0 0 1px rgba(255,255,255,0.7) inset;">i</span>',
            Tools::safeOutput($tooltipText)
        );
    }

    private function getEstimatedChildCountForRecommendation($shopId, $parentId = null)
    {
        if ($parentId !== null) {
            $sql = 'SELECT COUNT(*)
                    FROM `' . _DB_PREFIX_ . 'category` c
                    INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                        ON cs.id_category = c.id_category
                        AND cs.id_shop = ' . (int) $shopId . '
                    WHERE c.id_parent = ' . (int) $parentId;

            return (int) Db::getInstance()->getValue($sql);
        }

        $sql = 'SELECT COUNT(*) AS children_count
                FROM `' . _DB_PREFIX_ . 'category` c
                INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                    ON cs.id_category = c.id_category
                    AND cs.id_shop = ' . (int) $shopId . '
                WHERE c.id_parent > 0
                GROUP BY c.id_parent
                ORDER BY children_count DESC
                LIMIT 1';

        $maxCount = Db::getInstance()->getValue($sql);

        return $maxCount !== false ? (int) $maxCount : 0;
    }

    private function getRecommendedBatchRange($estimatedChildren)
    {
        $estimatedChildren = (int) $estimatedChildren;

        if ($estimatedChildren <= 200) {
            return [
                'min' => 100,
                'max' => 200,
                'severity_label' => 'LOW',
                'severity_color' => '#2E7D32',
            ];
        }

        if ($estimatedChildren <= 1000) {
            return [
                'min' => 200,
                'max' => 500,
                'severity_label' => 'MEDIUM',
                'severity_color' => '#EF6C00',
            ];
        }

        if ($estimatedChildren <= 3000) {
            return [
                'min' => 500,
                'max' => 1000,
                'severity_label' => 'HIGH',
                'severity_color' => '#C62828',
            ];
        }

        return [
            'min' => 1000,
            'max' => self::MAX_BATCH_SIZE,
            'severity_label' => 'VERY HIGH',
            'severity_color' => '#6A1B9A',
        ];
    }

    private function renderForm()
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $languages = Language::getLanguages(false);
        $dynamicRecommendation = $this->getDynamicBatchRecommendationText();

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Subcategory Sorting'),
                    'icon' => 'icon-sort-alpha-asc',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Parent category ID (optional)'),
                        'name' => self::CONFIG_CATEGORY_ID,
                        'desc' => $this->l('Leave empty to sort subcategories for all parent categories.'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Primary language for sorting'),
                        'name' => self::CONFIG_LANGUAGE_ID,
                        'options' => [
                            'query' => $this->buildLanguageOptions($languages),
                            'id' => 'id_lang',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('This language is used first when building the alphabetical order.'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Sort with all active languages in one run'),
                        'name' => self::CONFIG_SORT_ALL_LANGUAGES,
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Yes'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('No'),
                            ],
                        ],
                        'desc' => $this->l('If enabled, primary language is used first and all other active languages are used as tie-breakers.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Locale for alphabetic comparison'),
                        'name' => self::CONFIG_SORT_LOCALE,
                        'desc' => $this->l('Examples: de_DE, de_AT, en_US. Used for umlaut/accent-aware sorting when intl extension is available.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Update batch size'),
                        'name' => self::CONFIG_BATCH_SIZE,
                        'desc' => sprintf(
                            $this->l('Number of subcategories per DB transaction chunk. Allowed range: %d to %d. Recommendation: 100 for shared hosting, 200-500 for most shops, 500+ for strong servers. %s'),
                            self::MIN_BATCH_SIZE,
                            self::MAX_BATCH_SIZE,
                            $dynamicRecommendation
                        ),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save settings'),
                    'name' => 'submitInternautenCategoriesConfig',
                ],
                'buttons' => [
                    [
                        'title' => $this->l('Sort now'),
                        'name' => 'submitInternautenCategoriesSort',
                        'type' => 'submit',
                        'class' => 'btn btn-default pull-right',
                        'icon' => 'process-icon-save',
                    ],
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitInternautenCategoriesConfig';
        $helper->fields_value = [
            self::CONFIG_CATEGORY_ID => Configuration::get(self::CONFIG_CATEGORY_ID),
            self::CONFIG_LANGUAGE_ID => (int) Configuration::get(self::CONFIG_LANGUAGE_ID),
            self::CONFIG_SORT_ALL_LANGUAGES => (int) Configuration::get(self::CONFIG_SORT_ALL_LANGUAGES),
            self::CONFIG_SORT_LOCALE => (string) Configuration::get(self::CONFIG_SORT_LOCALE),
            self::CONFIG_BATCH_SIZE => (int) $this->getConfiguredBatchSize(),
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    private function buildLanguageOptions(array $languages)
    {
        $options = [];

        foreach ($languages as $language) {
            $options[] = [
                'id_lang' => (int) $language['id_lang'],
                'name' => sprintf('%s (%s)', $language['name'], $language['iso_code']),
            ];
        }

        return $options;
    }
}
