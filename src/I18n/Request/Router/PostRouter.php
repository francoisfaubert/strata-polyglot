<?php

namespace Polyglot\I18n\Request\Router;

use Strata\Strata;
use Strata\Utility\Hash;
use Strata\Model\CustomPostType\CustomPostType;
use Polyglot\I18n\Utility;

class PostRouter extends PolyglotRouter {

    public function localizeRoute($route = null)
    {
        if ($this->isSearchPage()) {
            return $this->localizeSearchRoute($route);
        }

        $localizedPost = $this->currentLocale->getTranslatedPost();
        $originalPost = $this->defaultLocale->getTranslatedPost();

        if ($originalPost) {
            $model = $this->getModelEntityByString($originalPost->post_type);
            if ($model) {
                $route = $this->removeLocalizedModelSlug($route, $model);
                $route = $this->removeLocalizedRoutedSlugs($route, $model);

                if ($localizedPost && $this->isTranslatedContent($localizedPost, $originalPost)) {
                    return $this->localizeContentRoute($route, $localizedPost, $originalPost);
                }
            }
            return $this->removeCurrentLocaleHomeUrl($route);
        }

        return $this->makeUrlFragment($route, $this->currentLocale);
    }


    private function removeLocalizedModelSlug($route, $model)
    {
        if ($this->shouldRewriteModelSlug($model)) {
            return Utility::replaceFirstOccurence(
                $model->getConfig("i18n." . $this->currentLocale->getCode() . ".rewrite.slug"),
                $model->getConfig("rewrite.slug"),
                $route
            );
        }

        return $route;
    }

    private function shouldRewriteModelSlug($model)
    {
        if (!$this->currentLocale->isDefault() || Strata::i18n()->shouldFallbackToDefaultLocale()) {
            if (is_a($model, "Strata\Model\WordpressEntity")) {
                return $model->hasConfig("i18n." . $this->currentLocale->getCode() . ".rewrite.slug");
            }
        }

        return false;
    }

    // Account for search pages which behave differently than regular pages
    protected function isSearchPage()
    {
        return is_search() && ($this->currentLocale->hasConfig("rewrite.search_base") || $this->currentLocale->isDefault());
    }

    protected function isTranslatedContent($localizedPost, $originalPost)
    {
        return  $this->isLocalizedPost($originalPost, $localizedPost) ||
                $this->isFallbackPost($originalPost, $localizedPost);
    }

    private function isLocalizedPost($originalPost, $localizedPost)
    {
        return !is_null($originalPost) && !is_null($localizedPost);
    }

    private function isFallbackPost($originalPost, $localizedPost)
    {
        return !is_null($originalPost) && is_null($localizedPost);
    }

    protected function localizeSearchRoute($route)
    {
        global $wp_rewrite;

        $impliedUrl = Utility::replaceFirstOccurence(
            $this->currentLocale->getHomeUrl(false) . $this->currentLocale->getConfig("rewrite.search_base") . "/",
            $this->defaultLocale->getHomeUrl(false) . $wp_rewrite->search_base . "/",
            $route
        );

        if (!$this->currentLocale->isDefault()) {
            $impliedUrl = Utility::replaceFirstOccurence(
                $this->defaultLocale->getHomeUrl(false) . $wp_rewrite->search_base . "/",
                $this->currentLocale->getHomeUrl(false) . $this->currentLocale->getConfig("rewrite.search_base") . "/",
                $impliedUrl
            );
        }

        return $this->makeUrlFragment($impliedUrl, $this->defaultLocale);
    }

    protected function localizeContentRoute($route, $localizedPost, $originalPost)
    {
        // Get permalink will append the current locale url when
        // the configuration allows locales to present content form
        // the default.
        $routedUrl = Utility::replaceFirstOccurence("/". $localizedPost->post_name . "/", "/". $originalPost->post_name . "/", $route);
        $originalUrl = Utility::replaceFirstOccurence($this->currentLocale->getHomeUrl(false), "/", $routedUrl);

        // Translate each parent url parts based on the default locale
        if ((int)$originalPost->post_parent > 0) {
            $originalParentPost = $this->defaultLocale->getTranslatedPost($originalPost->post_parent);
            $localizedParentPost = $this->currentLocale->getTranslatedPost($originalPost->post_parent);
            if ($originalParentPost && $localizedParentPost) {
                $originalUrl = $this->localizeContentRoute($originalUrl, $localizedParentPost, $originalParentPost);
            }
        }

        // Carry over get variables or trailing urls parts that aren't linked to the
        // permalink.
        $originalUrl = $this->localizeStaticSlugs($localizedPost, $routedUrl, $originalUrl);

        return $this->makeUrlFragment($originalUrl, $this->defaultLocale);
    }

    // When in fallback mode, we must send the original url stripped
    // locale code which is meaningless at that point.
    public function removeCurrentLocaleHomeUrl($route)
    {
        $originalUrl = Utility::replaceFirstOccurence($this->currentLocale->getHomeUrl(false), "/", $route);
        return $this->makeUrlFragment($originalUrl, $this->defaultLocale);
    }

    // At this point we have a working permalink but maybe the
    // original url had additional information afterwards.
    // Ex: A case CPT registered sub pages url.
    protected function localizeStaticSlugs($localizedPost, $routedUrl, $originalUrl)
    {
        // Localize back the parameters in the default language
        if (!$this->currentLocale->isDefault()) {
            if (preg_match('/'.preg_quote($localizedPost->post_name).'\/(.+?)$/', $routedUrl, $matches)) {
                $cpt = CustomPostType::factoryFromKey($localizedPost->post_type);
                $key = "i18n.".$this->currentLocale->getCode().".rewrite.slug";
                if ($cpt->hasConfig($key)) {
                    $additionalParameters = Utility::replaceFirstOccurence($cpt->getConfig($key), $cpt->getConfig("rewrite.slug"), $additionalParameters);
                }
            }
        }

        return trailingslashit($originalUrl);
    }
}
