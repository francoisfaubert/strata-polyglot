<?php

namespace Polyglot\I18n\Permalink;

use Strata\Strata;
use Polyglot\I18n\Utility;
use Strata\Utility\Hash;
use WP_Term;

class TermPermalinkManager extends PermalinkManager {

     /**
     * Returns the default term link to add the current locale prefix
     * to the generated link, if applicable.
     * @param  string $url
     * @param  WP_Term $term
     * @param  string $taxonomy
     * @return string
     */
    public function filter_onTermLink($url, WP_Term $term, $taxonomy)
    {
        $configuration = Strata::i18n()->getConfiguration();
        if ($configuration->isTaxonomyEnabled($taxonomy)) {

            $taxonomyDetails = get_taxonomy($taxonomy);
            if ($this->currentLocale->hasACustomUrl($taxonomy)) {
                $url = $this->replaceLocaleHomeUrl($url, $taxonomyDetails);
            }

            if ($this->taxonomyWasLocalizedInStrata($taxonomy)) {
                $url = $this->replaceDefaultTaxonomySlug($url, $taxonomyDetails);
            }
        }

        return $url;
    }

    private function replaceLocaleHomeUrl($permalink, $taxonomyDetails)
    {
        $taxonomyRootSlug = $taxonomyDetails->rewrite['slug'];

        return Utility::replaceFirstOccurence(
            '/' . $taxonomyRootSlug,
            $this->currentLocale->getHomeUrl(false) . $taxonomyRootSlug,
            $permalink
        );
    }

    private function taxonomyWasLocalizedInStrata($wordpressKey)
    {
        return !is_null(Strata::config("runtime.taxonomy.query_vars.$wordpressKey"));
    }

    private function replaceDefaultTaxonomySlug($url, $taxonomyDetails)
    {
        $localeCode = $this->currentLocale->getCode();

        if (Hash::check($taxonomyDetails->i18n, "$localeCode.rewrite.slug")) {
            return Utility::replaceFirstOccurence(
                $taxonomyDetails->rewrite['slug'],
                Hash::get($taxonomyDetails->i18n, "$localeCode.rewrite.slug"),
                $url
            );
        }

        return $url;
    }

}
