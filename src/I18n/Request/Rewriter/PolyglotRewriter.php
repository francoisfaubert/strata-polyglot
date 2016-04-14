<?php

namespace Polyglot\I18n\Request\Rewriter;

use Strata\Strata;
use Strata\I18n\I18n;
use Strata\Router\Rewriter;

abstract class PolyglotRewriter {

    abstract public function rewrite();

    protected $currentLocale;
    protected $defaultLocale;
    protected $rewriter;
    protected $urlRegex;
    protected $i18n;

    public function __construct(I18n $i18n, Rewriter $rewriter)
    {
        $this->rewriter = $rewriter;
        $this->i18n = $i18n;

        $this->currentLocale = $i18n->getCurrentLocale();
        $this->defaultLocale = $i18n->getDefaultLocale();

        $this->urlRegex = $this->getLocaleUrlsRegex();
    }

    private function getLocaleUrlsRegex()
    {
        return implode("|", $this->getLocaleUrls());
    }

    private function getLocaleUrls()
    {
        return array_map(function($locale) { return $locale->getUrl(); }, $this->i18n->getLocales());
    }
}
