<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class InternautenCategories extends Module
{
    private const AJAX_ACTION_CATEGORY_CHILDREN = 'icGetCategoryChildren';
    private const AJAX_ACTION_EMPTY_CATEGORIES = 'icGetEmptyCategories';
    private const AJAX_ACTION_HIDE_EMPTY_CATEGORIES = 'icHideEmptyCategories';
    private const AJAX_ACTION_HIDDEN_WITH_PRODUCTS = 'icGetHiddenWithProducts';
    private const AJAX_ACTION_SHOW_HIDDEN_CATEGORIES = 'icShowHiddenCategories';
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
        $this->version = '0.0.4';
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
        if (Tools::getValue('ajax') === '1') {
            $action = (string) Tools::getValue('action');

            if ($action === self::AJAX_ACTION_CATEGORY_CHILDREN) {
                $this->renderCategoryNavigatorAjax();

                return '';
            }

            if ($action === self::AJAX_ACTION_EMPTY_CATEGORIES) {
                $this->renderEmptyCategoriesAjax();

                return '';
            }

            if ($action === self::AJAX_ACTION_HIDE_EMPTY_CATEGORIES) {
                $this->renderHideEmptyCategoriesAjax();

                return '';
            }

            if ($action === self::AJAX_ACTION_HIDDEN_WITH_PRODUCTS) {
                $this->renderHiddenWithProductsAjax();

                return '';
            }

            if ($action === self::AJAX_ACTION_SHOW_HIDDEN_CATEGORIES) {
                $this->renderShowHiddenCategoriesAjax();

                return '';
            }
        }

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

    private function renderCategoryNavigatorAjax()
    {
        $shopId = (int) $this->context->shop->id;
        $languageId = (int) $this->context->language->id;
        $defaultParentId = (int) Configuration::get('PS_HOME_CATEGORY');
        $parentId = (int) Tools::getValue('parent_id', $defaultParentId);

        if ($parentId <= 0 || !Category::categoryExists($parentId, $shopId)) {
            $this->sendCategoryNavigatorJson([
                'ok' => false,
                'error' => $this->l('Requested category does not exist in this shop.'),
            ]);
        }

        $children = $this->getCategoryNavigatorChildren($parentId, $languageId, $shopId);

        $this->sendCategoryNavigatorJson([
            'ok' => true,
            'parent_id' => $parentId,
            'children' => $children,
        ]);
    }

    private function sendCategoryNavigatorJson(array $payload)
    {
        header('Content-Type: application/json; charset=utf-8');
        die((string) json_encode($payload));
    }

    private function renderEmptyCategoriesAjax()
    {
        $shopId = (int) $this->context->shop->id;
        $languageId = (int) $this->context->language->id;

        $emptyCategories = $this->getEmptyCategoriesForDialog($shopId, $languageId);

        $this->sendCategoryNavigatorJson([
            'ok' => true,
            'categories' => $emptyCategories,
        ]);
    }

    private function renderHideEmptyCategoriesAjax()
    {
        $shopId = (int) $this->context->shop->id;
        $categoryIdsRaw = trim((string) Tools::getValue('category_ids', ''));

        if ($categoryIdsRaw === '') {
            $this->sendCategoryNavigatorJson([
                'ok' => false,
                'error' => $this->l('No categories selected.'),
            ]);
        }

        $parts = array_filter(array_map('trim', explode(',', $categoryIdsRaw)), static function ($value) {
            return $value !== '';
        });

        $categoryIds = [];
        foreach ($parts as $part) {
            if (!ctype_digit($part) || (int) $part <= 0) {
                $this->sendCategoryNavigatorJson([
                    'ok' => false,
                    'error' => $this->l('Selected categories could not be updated.'),
                ]);
            }

            $categoryIds[] = (int) $part;
        }

        $categoryIds = array_values(array_unique($categoryIds));
        if (empty($categoryIds)) {
            $this->sendCategoryNavigatorJson([
                'ok' => false,
                'error' => $this->l('No categories selected.'),
            ]);
        }

        $idsSql = implode(',', $categoryIds);
        $db = Db::getInstance();
        $categoryShopHasActiveColumn = $this->categoryShopHasActiveColumn();

        $updatedShop = true;
        if ($categoryShopHasActiveColumn) {
            $updatedShop = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'category_shop`
             SET `active` = 0
             WHERE `id_shop` = ' . (int) $shopId . '
               AND `id_category` IN (' . $idsSql . ')'
            );
        }

        $updatedCategory = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'category`
             SET `active` = 0
             WHERE `id_category` IN (' . $idsSql . ')'
        );

        if (!$updatedShop || !$updatedCategory) {
            $this->sendCategoryNavigatorJson([
                'ok' => false,
                'error' => $this->l('Selected categories could not be updated.'),
            ]);
        }

        $this->sendCategoryNavigatorJson([
            'ok' => true,
            'updated' => count($categoryIds),
            'message' => $this->l('Selected categories were set to hidden.'),
        ]);
    }

    private function renderHiddenWithProductsAjax()
    {
        $shopId = (int) $this->context->shop->id;
        $languageId = (int) $this->context->language->id;

        $hiddenCategories = $this->getHiddenCategoriesWithProductsForDialog($shopId, $languageId);

        $this->sendCategoryNavigatorJson([
            'ok' => true,
            'categories' => $hiddenCategories,
        ]);
    }

    private function renderShowHiddenCategoriesAjax()
    {
        $shopId = (int) $this->context->shop->id;
        $categoryIdsRaw = trim((string) Tools::getValue('category_ids', ''));

        if ($categoryIdsRaw === '') {
            $this->sendCategoryNavigatorJson([
                'ok' => false,
                'error' => $this->l('No categories selected.'),
            ]);
        }

        $parts = array_filter(array_map('trim', explode(',', $categoryIdsRaw)), static function ($value) {
            return $value !== '';
        });

        $categoryIds = [];
        foreach ($parts as $part) {
            if (!ctype_digit($part) || (int) $part <= 0) {
                $this->sendCategoryNavigatorJson([
                    'ok' => false,
                    'error' => $this->l('Selected categories could not be updated.'),
                ]);
            }

            $categoryIds[] = (int) $part;
        }

        $categoryIds = array_values(array_unique($categoryIds));
        if (empty($categoryIds)) {
            $this->sendCategoryNavigatorJson([
                'ok' => false,
                'error' => $this->l('No categories selected.'),
            ]);
        }

        $idsSql = implode(',', $categoryIds);
        $db = Db::getInstance();
        $categoryShopHasActiveColumn = $this->categoryShopHasActiveColumn();

        $updatedShop = true;
        if ($categoryShopHasActiveColumn) {
            $updatedShop = $db->execute(
                'UPDATE `' . _DB_PREFIX_ . 'category_shop`
                 SET `active` = 1
                 WHERE `id_shop` = ' . (int) $shopId . '
                   AND `id_category` IN (' . $idsSql . ')'
            );
        }

        $updatedCategory = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'category`
             SET `active` = 1
             WHERE `id_category` IN (' . $idsSql . ')'
        );

        if (!$updatedShop || !$updatedCategory) {
            $this->sendCategoryNavigatorJson([
                'ok' => false,
                'error' => $this->l('Selected categories could not be updated.'),
            ]);
        }

        $this->sendCategoryNavigatorJson([
            'ok' => true,
            'updated' => count($categoryIds),
            'message' => $this->l('Selected categories were set to visible.'),
        ]);
    }

    private function getEmptyCategoriesForDialog($shopId, $languageId)
    {
        $categoryShopHasActiveColumn = $this->categoryShopHasActiveColumn();
        $categoryShopActiveFilter = $categoryShopHasActiveColumn ? "\n                    AND cs.active = 1" : '';

        $sql = 'SELECT c.id_category, cl.name
                FROM `' . _DB_PREFIX_ . 'category` c
                INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                    ON cs.id_category = c.id_category
                    AND cs.id_shop = ' . (int) $shopId . '
                INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                    ON cl.id_category = c.id_category
                    AND cl.id_lang = ' . (int) $languageId . '
                    AND cl.id_shop = ' . (int) $shopId . '
                WHERE c.id_parent > 0
                    AND c.active = 1
                    ' . $categoryShopActiveFilter . '
                    AND NOT EXISTS (
                        SELECT 1
                        FROM `' . _DB_PREFIX_ . 'category` c2
                        INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs2
                            ON cs2.id_category = c2.id_category
                            AND cs2.id_shop = ' . (int) $shopId . '
                        WHERE c2.id_parent = c.id_category
                    )
                    AND NOT EXISTS (
                        SELECT 1
                        FROM `' . _DB_PREFIX_ . 'category_product` cp
                        WHERE cp.id_category = c.id_category
                    )
                ORDER BY cl.name ASC';

        $rows = Db::getInstance()->executeS($sql);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static function ($row) {
            return [
                'id' => (int) $row['id_category'],
                'name' => (string) $row['name'],
            ];
        }, $rows);
    }

    private function getHiddenCategoriesWithProductsForDialog($shopId, $languageId)
    {
        $categoryShopHasActiveColumn = $this->categoryShopHasActiveColumn();
        $hiddenFilter = $categoryShopHasActiveColumn
            ? '(c.active = 0 OR cs.active = 0)'
            : 'c.active = 0';

        $sql = 'SELECT c.id_category, cl.name
                FROM `' . _DB_PREFIX_ . 'category` c
                INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                    ON cs.id_category = c.id_category
                    AND cs.id_shop = ' . (int) $shopId . '
                INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                    ON cl.id_category = c.id_category
                    AND cl.id_lang = ' . (int) $languageId . '
                    AND cl.id_shop = ' . (int) $shopId . '
                WHERE c.id_parent > 0
                    AND ' . $hiddenFilter . '
                    AND EXISTS (
                        SELECT 1
                        FROM `' . _DB_PREFIX_ . 'category_product` cp
                        WHERE cp.id_category = c.id_category
                    )
                ORDER BY cl.name ASC';

        $rows = Db::getInstance()->executeS($sql);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static function ($row) {
            return [
                'id' => (int) $row['id_category'],
                'name' => (string) $row['name'],
            ];
        }, $rows);
    }

    private function categoryShopHasActiveColumn()
    {
        static $hasActiveColumn = null;

        if ($hasActiveColumn !== null) {
            return $hasActiveColumn;
        }

        $sql = 'SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'category_shop` LIKE "active"';
        $rows = Db::getInstance()->executeS($sql);
        $hasActiveColumn = is_array($rows) && !empty($rows);

        return $hasActiveColumn;
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
                ORDER BY children_count DESC';

        $maxCount = Db::getInstance()->getValue($sql);

        return $maxCount !== false ? (int) $maxCount : 0;
    }

    private function getCategoryNavigatorChildren($parentId, $languageId, $shopId)
    {
        $sql = 'SELECT c.id_category, cl.name,
                    (
                        SELECT COUNT(*)
                        FROM `' . _DB_PREFIX_ . 'category` c2
                        INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs2
                            ON cs2.id_category = c2.id_category
                            AND cs2.id_shop = ' . (int) $shopId . '
                        WHERE c2.id_parent = c.id_category
                    ) AS child_count
                FROM `' . _DB_PREFIX_ . 'category` c
                INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                    ON cs.id_category = c.id_category
                    AND cs.id_shop = ' . (int) $shopId . '
                INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                    ON cl.id_category = c.id_category
                    AND cl.id_lang = ' . (int) $languageId . '
                    AND cl.id_shop = ' . (int) $shopId . '
                WHERE c.id_parent = ' . (int) $parentId . '
                ORDER BY cl.name ASC';

        $rows = Db::getInstance()->executeS($sql);
        if (!is_array($rows)) {
            return [];
        }

        $children = [];
        foreach ($rows as $row) {
            $children[] = [
                'id' => (int) $row['id_category'],
                'name' => (string) $row['name'],
                'has_children' => ((int) $row['child_count']) > 0,
            ];
        }

        return $children;
    }

    private function renderCategoryNavigatorPanel()
    {
        $defaultParentId = (int) Configuration::get('PS_HOME_CATEGORY');
        $configuredParentRaw = trim((string) Configuration::get(self::CONFIG_CATEGORY_ID));
        $selectedSuffix = $configuredParentRaw !== '' ? ' ' . (int) $configuredParentRaw : '';
        $ajaxUrl = AdminController::$currentIndex
            . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules')
            . '&ajax=1&action=' . self::AJAX_ACTION_CATEGORY_CHILDREN;
        $emptyCategoriesAjaxUrl = AdminController::$currentIndex
            . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules')
            . '&ajax=1&action=' . self::AJAX_ACTION_EMPTY_CATEGORIES;
        $hideEmptyCategoriesAjaxUrl = AdminController::$currentIndex
            . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules')
            . '&ajax=1&action=' . self::AJAX_ACTION_HIDE_EMPTY_CATEGORIES;
        $hiddenWithProductsAjaxUrl = AdminController::$currentIndex
            . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules')
            . '&ajax=1&action=' . self::AJAX_ACTION_HIDDEN_WITH_PRODUCTS;
        $showHiddenCategoriesAjaxUrl = AdminController::$currentIndex
            . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules')
            . '&ajax=1&action=' . self::AJAX_ACTION_SHOW_HIDDEN_CATEGORIES;

        $texts = [
            'title' => $this->l('Category navigator'),
            'description' => $this->l('Click through categories to choose a parent category ID. Clicking a category sets the ID and opens its subcategories.'),
            'back' => $this->l('Back'),
            'root' => $this->l('Top level'),
            'selected' => $this->l('Selected parent ID:'),
            'loading' => $this->l('Loading categories...'),
            'empty' => $this->l('No subcategories available.'),
            'error' => $this->l('Categories could not be loaded.'),
            'open' => $this->l('Open'),
            'leaf' => $this->l('No subcategories'),
            'show_empty' => $this->l('Show empty categories'),
            'empty_dialog_title' => $this->l('Empty categories (no subcategories, no products)'),
            'close' => $this->l('Close'),
            'empty_categories_loading' => $this->l('Loading empty categories...'),
            'empty_categories_none' => $this->l('No empty categories found.'),
            'empty_categories_error' => $this->l('Empty categories could not be loaded.'),
            'hide_selected' => $this->l('Hide selected categories in this shop'),
            'hiding_in_progress' => $this->l('Updating categories...'),
            'hide_selected_success' => $this->l('Selected categories were set to hidden.'),
            'hide_selected_error' => $this->l('Selected categories could not be updated.'),
            'show_hidden_with_products' => $this->l('Show hidden categories with products'),
            'hidden_dialog_title' => $this->l('Hidden categories with products'),
            'hidden_categories_loading' => $this->l('Loading hidden categories...'),
            'hidden_categories_none' => $this->l('No hidden categories with products found.'),
            'hidden_categories_error' => $this->l('Hidden categories could not be loaded.'),
            'show_selected' => $this->l('Show selected categories in this shop'),
            'show_selected_success' => $this->l('Selected categories were set to visible.'),
            'show_selected_error' => $this->l('Selected categories could not be updated.'),
        ];

        $script = '<script type="text/javascript">
(function () {
    var navigatorRoot = document.getElementById("ic-category-navigator");
    if (!navigatorRoot) {
        return;
    }

    var configInput = document.getElementById("' . pSQL(self::CONFIG_CATEGORY_ID) . '");
    var listEl = navigatorRoot.querySelector("[data-role=ic-list]");
    var pathEl = navigatorRoot.querySelector("[data-role=ic-path]");
    var selectedEl = navigatorRoot.querySelector("[data-role=ic-selected]");
    var backBtn = navigatorRoot.querySelector("[data-role=ic-back]");
    var showEmptyBtn = navigatorRoot.querySelector("[data-role=ic-show-empty]");
    var showHiddenWithProductsBtn = navigatorRoot.querySelector("[data-role=ic-show-hidden-with-products]");
    var emptyDialog = navigatorRoot.querySelector("[data-role=ic-empty-dialog]");
    var emptyDialogCloseBtn = navigatorRoot.querySelector("[data-role=ic-empty-close]");
    var emptyDialogHideBtn = navigatorRoot.querySelector("[data-role=ic-empty-hide-selected]");
    var emptyDialogList = navigatorRoot.querySelector("[data-role=ic-empty-list]");
    var hiddenDialog = navigatorRoot.querySelector("[data-role=ic-hidden-dialog]");
    var hiddenDialogCloseBtn = navigatorRoot.querySelector("[data-role=ic-hidden-close]");
    var hiddenDialogShowBtn = navigatorRoot.querySelector("[data-role=ic-hidden-show-selected]");
    var hiddenDialogList = navigatorRoot.querySelector("[data-role=ic-hidden-list]");

    var texts = ' . json_encode($texts) . ';
    var ajaxBaseUrl = ' . json_encode($ajaxUrl) . ';
    var emptyCategoriesAjaxUrl = ' . json_encode($emptyCategoriesAjaxUrl) . ';
    var hideEmptyCategoriesAjaxUrl = ' . json_encode($hideEmptyCategoriesAjaxUrl) . ';
    var hiddenWithProductsAjaxUrl = ' . json_encode($hiddenWithProductsAjaxUrl) . ';
    var showHiddenCategoriesAjaxUrl = ' . json_encode($showHiddenCategoriesAjaxUrl) . ';
    var rootParentId = ' . (int) $defaultParentId . ';
    var stateStack = [];

    function findParentListItem(element, listRoot) {
        var current = element;
        while (current && current !== listRoot) {
            if (current.tagName && current.tagName.toLowerCase() === "li") {
                return current;
            }
            current = current.parentNode;
        }

        return null;
    }

    function updateHideSelectedButtonState() {
        if (!emptyDialogHideBtn || !emptyDialogList) {
            return;
        }

        var checkedCount = emptyDialogList.querySelectorAll("input[type=checkbox][data-role=ic-empty-checkbox]:checked").length;
        emptyDialogHideBtn.disabled = checkedCount === 0;
    }

    function setEmptyDialogStatus(message, isError) {
        var statusEl = navigatorRoot.querySelector("[data-role=ic-empty-status]");
        if (!statusEl) {
            return;
        }

        statusEl.textContent = message ? String(message) : "";
        statusEl.className = isError ? "ic-empty-dialog__status text-danger" : "ic-empty-dialog__status text-success";
    }

    function updateShowSelectedButtonState() {
        if (!hiddenDialogShowBtn || !hiddenDialogList) {
            return;
        }

        var checkedCount = hiddenDialogList.querySelectorAll("input[type=checkbox][data-role=ic-hidden-checkbox]:checked").length;
        hiddenDialogShowBtn.disabled = checkedCount === 0;
    }

    function setHiddenDialogStatus(message, isError) {
        var statusEl = navigatorRoot.querySelector("[data-role=ic-hidden-status]");
        if (!statusEl) {
            return;
        }

        statusEl.textContent = message ? String(message) : "";
        statusEl.className = isError ? "ic-hidden-dialog__status text-danger" : "ic-hidden-dialog__status text-success";
    }

    function setSelected(id) {
        if (!configInput) {
            return;
        }

        configInput.value = String(id);
        selectedEl.textContent = texts.selected + " " + id;
    }

    function setLoading() {
        listEl.innerHTML = "<li>" + texts.loading + "</li>";
    }

    function setError(message) {
        listEl.innerHTML = "<li class=\"text-danger\">" + message + "</li>";
    }

    function updatePath() {
        if (!stateStack.length) {
            pathEl.textContent = texts.root;
            backBtn.disabled = true;
            return;
        }

        var names = stateStack.map(function (entry) { return entry.name; });
        pathEl.textContent = texts.root + " / " + names.join(" / ");
        backBtn.disabled = stateStack.length === 0;
    }

    function renderChildren(children) {
        if (!Array.isArray(children) || !children.length) {
            listEl.innerHTML = "<li>" + texts.empty + "</li>";
            return;
        }

        listEl.innerHTML = "";

        children.forEach(function (child) {
            var row = document.createElement("li");
            row.className = "ic-category-item";

            var label = document.createElement("button");
            label.type = "button";
            label.className = "btn btn-link ic-category-select";
            label.textContent = child.name + " (#" + child.id + ")";
            label.addEventListener("click", function () {
                setSelected(child.id);

                if (!child.has_children) {
                    return;
                }

                stateStack.push({ id: child.id, name: child.name });
                updatePath();
                loadChildren(child.id);
            });
            row.appendChild(label);

            var suffix = document.createElement("span");
            suffix.className = "ic-category-suffix";
            suffix.textContent = child.has_children ? texts.open : texts.leaf;
            row.appendChild(suffix);

            listEl.appendChild(row);
        });
    }

    function loadChildren(parentId) {
        setLoading();

        fetch(ajaxBaseUrl + "&parent_id=" + encodeURIComponent(parentId), {
            credentials: "same-origin"
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (!data || !data.ok) {
                setError((data && data.error) ? data.error : texts.error);
                return;
            }

            renderChildren(data.children);
        }).catch(function () {
            setError(texts.error);
        });
    }

    function renderEmptyCategories(categories) {
        if (!Array.isArray(categories) || !categories.length) {
            emptyDialogList.innerHTML = "<li>" + texts.empty_categories_none + "</li>";
            updateHideSelectedButtonState();
            return;
        }

        emptyDialogList.innerHTML = "";

        categories.forEach(function (category) {
            var row = document.createElement("li");
            row.className = "ic-empty-dialog__row";
            row.setAttribute("data-role", "ic-empty-row");

            var rowLabel = document.createElement("label");
            rowLabel.className = "ic-empty-dialog__row-label";

            var checkbox = document.createElement("input");
            checkbox.type = "checkbox";
            checkbox.setAttribute("data-role", "ic-empty-checkbox");
            checkbox.setAttribute("data-category-id", String(category.id));

            var rowText = document.createElement("span");
            rowText.textContent = category.name + " (#" + category.id + ")";

            rowLabel.appendChild(checkbox);
            rowLabel.appendChild(rowText);
            row.appendChild(rowLabel);
            emptyDialogList.appendChild(row);
        });

        updateHideSelectedButtonState();
    }

    function renderHiddenCategories(categories) {
        if (!Array.isArray(categories) || !categories.length) {
            hiddenDialogList.innerHTML = "<li>" + texts.hidden_categories_none + "</li>";
            updateShowSelectedButtonState();
            return;
        }

        hiddenDialogList.innerHTML = "";

        categories.forEach(function (category) {
            var row = document.createElement("li");
            row.className = "ic-hidden-dialog__row";
            row.setAttribute("data-role", "ic-hidden-row");

            var rowLabel = document.createElement("label");
            rowLabel.className = "ic-hidden-dialog__row-label";

            var checkbox = document.createElement("input");
            checkbox.type = "checkbox";
            checkbox.setAttribute("data-role", "ic-hidden-checkbox");
            checkbox.setAttribute("data-category-id", String(category.id));

            var rowText = document.createElement("span");
            rowText.textContent = category.name + " (#" + category.id + ")";

            rowLabel.appendChild(checkbox);
            rowLabel.appendChild(rowText);
            row.appendChild(rowLabel);
            hiddenDialogList.appendChild(row);
        });

        updateShowSelectedButtonState();
    }

    function openEmptyCategoriesDialog() {
        if (!emptyDialog || !emptyDialogList) {
            return;
        }

        setEmptyDialogStatus("", false);
        emptyDialogList.innerHTML = "<li>" + texts.empty_categories_loading + "</li>";

        if (typeof emptyDialog.showModal === "function") {
            emptyDialog.showModal();
        } else {
            emptyDialog.setAttribute("open", "open");
        }

        fetch(emptyCategoriesAjaxUrl, {
            credentials: "same-origin"
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (!data || !data.ok) {
                emptyDialogList.innerHTML = "<li class=\"text-danger\">" + ((data && data.error) ? data.error : texts.empty_categories_error) + "</li>";
                return;
            }

            renderEmptyCategories(data.categories);
        }).catch(function () {
            emptyDialogList.innerHTML = "<li class=\"text-danger\">" + texts.empty_categories_error + "</li>";
            updateHideSelectedButtonState();
        });
    }

    function closeEmptyCategoriesDialog() {
        if (!emptyDialog) {
            return;
        }

        if (typeof emptyDialog.close === "function") {
            emptyDialog.close();
        } else {
            emptyDialog.removeAttribute("open");
        }
    }

    function openHiddenWithProductsDialog() {
        if (!hiddenDialog || !hiddenDialogList) {
            return;
        }

        setHiddenDialogStatus("", false);
        hiddenDialogList.innerHTML = "<li>" + texts.hidden_categories_loading + "</li>";

        if (typeof hiddenDialog.showModal === "function") {
            hiddenDialog.showModal();
        } else {
            hiddenDialog.setAttribute("open", "open");
        }

        fetch(hiddenWithProductsAjaxUrl, {
            credentials: "same-origin"
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (!data || !data.ok) {
                hiddenDialogList.innerHTML = "<li class=\"text-danger\">" + ((data && data.error) ? data.error : texts.hidden_categories_error) + "</li>";
                return;
            }

            renderHiddenCategories(data.categories);
        }).catch(function () {
            hiddenDialogList.innerHTML = "<li class=\"text-danger\">" + texts.hidden_categories_error + "</li>";
            updateShowSelectedButtonState();
        });
    }

    function closeHiddenWithProductsDialog() {
        if (!hiddenDialog) {
            return;
        }

        if (typeof hiddenDialog.close === "function") {
            hiddenDialog.close();
        } else {
            hiddenDialog.removeAttribute("open");
        }
    }

    backBtn.addEventListener("click", function () {
        if (!stateStack.length) {
            return;
        }

        stateStack.pop();
        updatePath();

        var parentId = stateStack.length ? stateStack[stateStack.length - 1].id : rootParentId;
        loadChildren(parentId);
    });

    if (showEmptyBtn) {
        showEmptyBtn.addEventListener("click", function () {
            openEmptyCategoriesDialog();
        });
    }

    if (showHiddenWithProductsBtn) {
        showHiddenWithProductsBtn.addEventListener("click", function () {
            openHiddenWithProductsDialog();
        });
    }

    if (emptyDialogCloseBtn) {
        emptyDialogCloseBtn.addEventListener("click", function () {
            closeEmptyCategoriesDialog();
        });
    }

    if (emptyDialogHideBtn) {
        emptyDialogHideBtn.addEventListener("click", function () {
            if (!emptyDialogList) {
                return;
            }

            var checkedBoxes = emptyDialogList.querySelectorAll("input[type=checkbox][data-role=ic-empty-checkbox]:checked");
            if (!checkedBoxes.length) {
                updateHideSelectedButtonState();
                return;
            }

            var selectedCategoryIds = [];
            for (var index = 0; index < checkedBoxes.length; index++) {
                var checkbox = checkedBoxes[index];
                var categoryId = checkbox.getAttribute("data-category-id");
                if (categoryId) {
                    selectedCategoryIds.push(categoryId);
                }
            }

            if (!selectedCategoryIds.length) {
                setEmptyDialogStatus(texts.hide_selected_error, true);
                updateHideSelectedButtonState();
                return;
            }

            emptyDialogHideBtn.disabled = true;
            setEmptyDialogStatus(texts.hiding_in_progress, false);

            fetch(hideEmptyCategoriesAjaxUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
                },
                body: "category_ids=" + encodeURIComponent(selectedCategoryIds.join(","))
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                if (!data || !data.ok) {
                    setEmptyDialogStatus((data && data.error) ? data.error : texts.hide_selected_error, true);
                    updateHideSelectedButtonState();
                    return;
                }

                for (var hideIndex = 0; hideIndex < checkedBoxes.length; hideIndex++) {
                    var checked = checkedBoxes[hideIndex];
                    var rowToHide = findParentListItem(checked, emptyDialogList);
                    if (rowToHide) {
                        rowToHide.className += " ic-empty-dialog__row--hidden";
                    }
                    checked.checked = false;
                }

                setEmptyDialogStatus(data.message ? data.message : texts.hide_selected_success, false);
                updateHideSelectedButtonState();
            }).catch(function () {
                setEmptyDialogStatus(texts.hide_selected_error, true);
                updateHideSelectedButtonState();
            });
        });
    }

    if (hiddenDialogCloseBtn) {
        hiddenDialogCloseBtn.addEventListener("click", function () {
            closeHiddenWithProductsDialog();
        });
    }

    if (hiddenDialogShowBtn) {
        hiddenDialogShowBtn.addEventListener("click", function () {
            if (!hiddenDialogList) {
                return;
            }

            var checkedBoxes = hiddenDialogList.querySelectorAll("input[type=checkbox][data-role=ic-hidden-checkbox]:checked");
            if (!checkedBoxes.length) {
                updateShowSelectedButtonState();
                return;
            }

            var selectedCategoryIds = [];
            for (var index = 0; index < checkedBoxes.length; index++) {
                var checkbox = checkedBoxes[index];
                var categoryId = checkbox.getAttribute("data-category-id");
                if (categoryId) {
                    selectedCategoryIds.push(categoryId);
                }
            }

            if (!selectedCategoryIds.length) {
                setHiddenDialogStatus(texts.show_selected_error, true);
                updateShowSelectedButtonState();
                return;
            }

            hiddenDialogShowBtn.disabled = true;
            setHiddenDialogStatus(texts.hiding_in_progress, false);

            fetch(showHiddenCategoriesAjaxUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
                },
                body: "category_ids=" + encodeURIComponent(selectedCategoryIds.join(","))
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                if (!data || !data.ok) {
                    setHiddenDialogStatus((data && data.error) ? data.error : texts.show_selected_error, true);
                    updateShowSelectedButtonState();
                    return;
                }

                for (var showIndex = 0; showIndex < checkedBoxes.length; showIndex++) {
                    var checked = checkedBoxes[showIndex];
                    var rowToHide = findParentListItem(checked, hiddenDialogList);
                    if (rowToHide) {
                        rowToHide.className += " ic-hidden-dialog__row--shown";
                    }
                    checked.checked = false;
                }

                setHiddenDialogStatus(data.message ? data.message : texts.show_selected_success, false);
                updateShowSelectedButtonState();
            }).catch(function () {
                setHiddenDialogStatus(texts.show_selected_error, true);
                updateShowSelectedButtonState();
            });
        });
    }

    if (emptyDialogList) {
        emptyDialogList.addEventListener("change", function (event) {
            var target = event.target;
            if (target && target.getAttribute && target.getAttribute("data-role") === "ic-empty-checkbox") {
                updateHideSelectedButtonState();
            }
        });
    }

    if (hiddenDialogList) {
        hiddenDialogList.addEventListener("change", function (event) {
            var target = event.target;
            if (target && target.getAttribute && target.getAttribute("data-role") === "ic-hidden-checkbox") {
                updateShowSelectedButtonState();
            }
        });
    }

    if (emptyDialog) {
        emptyDialog.addEventListener("cancel", function (event) {
            event.preventDefault();
            closeEmptyCategoriesDialog();
        });
    }

    if (hiddenDialog) {
        hiddenDialog.addEventListener("cancel", function (event) {
            event.preventDefault();
            closeHiddenWithProductsDialog();
        });
    }

    if (configInput && configInput.value) {
        selectedEl.textContent = texts.selected + " " + configInput.value;
    }

    updatePath();
    loadChildren(rootParentId);
})();
</script>';

        return '
<div id="ic-category-navigator" class="panel" style="margin-top:12px;">
    <h3><i class="icon-sitemap"></i> ' . Tools::safeOutput($texts['title']) . '</h3>
    <p>' . Tools::safeOutput($texts['description']) . '</p>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
        <button type="button" class="btn btn-default" data-role="ic-back">' . Tools::safeOutput($texts['back']) . '</button>
        <button type="button" class="btn btn-default" data-role="ic-show-empty">' . Tools::safeOutput($texts['show_empty']) . '</button>
        <button type="button" class="btn btn-default" data-role="ic-show-hidden-with-products">' . Tools::safeOutput($texts['show_hidden_with_products']) . '</button>
        <strong data-role="ic-path">' . Tools::safeOutput($texts['root']) . '</strong>
    </div>
    <div data-role="ic-selected" style="margin-bottom:8px;">' . Tools::safeOutput($texts['selected']) . Tools::safeOutput($selectedSuffix) . '</div>
    <ul data-role="ic-list" class="list-unstyled" style="margin:0;padding:0;display:flex;flex-direction:column;gap:6px;">
        <li>' . Tools::safeOutput($texts['loading']) . '</li>
    </ul>
    <dialog data-role="ic-empty-dialog" class="ic-empty-dialog">
        <div class="ic-empty-dialog__header">
            <strong>' . Tools::safeOutput($texts['empty_dialog_title']) . '</strong>
        </div>
        <ul data-role="ic-empty-list" class="list-unstyled ic-empty-dialog__list">
            <li>' . Tools::safeOutput($texts['empty_categories_loading']) . '</li>
        </ul>
        <div data-role="ic-empty-status" class="ic-empty-dialog__status"></div>
        <div class="ic-empty-dialog__actions">
            <button type="button" class="btn btn-default" data-role="ic-empty-hide-selected" disabled="disabled">' . Tools::safeOutput($texts['hide_selected']) . '</button>
            <button type="button" class="btn btn-default" data-role="ic-empty-close">' . Tools::safeOutput($texts['close']) . '</button>
        </div>
    </dialog>
    <dialog data-role="ic-hidden-dialog" class="ic-hidden-dialog">
        <div class="ic-hidden-dialog__header">
            <strong>' . Tools::safeOutput($texts['hidden_dialog_title']) . '</strong>
        </div>
        <ul data-role="ic-hidden-list" class="list-unstyled ic-hidden-dialog__list">
            <li>' . Tools::safeOutput($texts['hidden_categories_loading']) . '</li>
        </ul>
        <div data-role="ic-hidden-status" class="ic-hidden-dialog__status"></div>
        <div class="ic-hidden-dialog__actions">
            <button type="button" class="btn btn-default" data-role="ic-hidden-show-selected" disabled="disabled">' . Tools::safeOutput($texts['show_selected']) . '</button>
            <button type="button" class="btn btn-default" data-role="ic-hidden-close">' . Tools::safeOutput($texts['close']) . '</button>
        </div>
    </dialog>
</div>
<style>
    #ic-category-navigator .ic-category-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: 1px solid #d6d4d4;
        border-radius: 4px;
        background: #fff;
        padding: 4px 8px;
    }

    #ic-category-navigator .ic-category-select {
        padding-left: 0;
        white-space: normal;
        text-align: left;
        flex: 1;
    }

    #ic-category-navigator .ic-category-suffix {
        font-size: 12px;
        color: #666;
        margin-left: 8px;
    }

    #ic-category-navigator .ic-empty-dialog,
    #ic-category-navigator .ic-hidden-dialog {
        width: min(700px, calc(100vw - 32px));
        max-height: 70vh;
        border: 1px solid #d6d4d4;
        border-radius: 4px;
        padding: 12px;
    }

    #ic-category-navigator .ic-empty-dialog::backdrop,
    #ic-category-navigator .ic-hidden-dialog::backdrop {
        background: rgba(0, 0, 0, 0.35);
    }

    #ic-category-navigator .ic-empty-dialog__header,
    #ic-category-navigator .ic-hidden-dialog__header {
        margin-bottom: 10px;
    }

    #ic-category-navigator .ic-empty-dialog__list,
    #ic-category-navigator .ic-hidden-dialog__list {
        max-height: 45vh;
        overflow: auto;
        border: 1px solid #d6d4d4;
        border-radius: 4px;
        padding: 8px;
        margin: 0;
    }

    #ic-category-navigator .ic-empty-dialog__list li,
    #ic-category-navigator .ic-hidden-dialog__list li {
        padding: 3px 0;
        border-bottom: 1px solid #f1f1f1;
    }

    #ic-category-navigator .ic-empty-dialog__row-label,
    #ic-category-navigator .ic-hidden-dialog__row-label {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin: 0;
        font-weight: normal;
        cursor: pointer;
    }

    #ic-category-navigator .ic-empty-dialog__row--hidden {
        display: none !important;
    }

    #ic-category-navigator .ic-hidden-dialog__row--shown {
        display: none !important;
    }

    #ic-category-navigator .ic-empty-dialog__list li:last-child,
    #ic-category-navigator .ic-hidden-dialog__list li:last-child {
        border-bottom: 0;
    }

    #ic-category-navigator .ic-empty-dialog__actions,
    #ic-category-navigator .ic-hidden-dialog__actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        margin-top: 10px;
    }

    #ic-category-navigator .ic-empty-dialog__status,
    #ic-category-navigator .ic-hidden-dialog__status {
        min-height: 20px;
        margin-top: 8px;
    }
</style>
' . $script;
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

        return $helper->generateForm([$fieldsForm]) . $this->renderCategoryNavigatorPanel();
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
