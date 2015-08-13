<h3><?php echo sprintf(__("Translations of '%s'", 'polyglot'), $originalTitle); ?></h3>

<table class="widefat">
<?php $idx = 0; foreach ($polyglot->getLocales() as $code => $locale) : ?>

    <tr class="<?php echo ($idx % 2) === 0 ? "even" : "odd" ?>">
        <td>
            <?php if ($locale->isDefault()) : ?>
                <strong>
            <?php endif; ?>

            <code><?php echo $locale->getCode(); ?></code>

            <?php if ($locale->hasANativeLabel()) : ?>
                 <?php echo $locale->getNativeLabel(); ?>
            <?php endif; ?>


            <?php if ($locale->isDefault()) : ?>
                </strong>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($mode === "post") : ?>
                <?php if ($locale->hasPostTranslation($objId)) : ?>
                    <a class="button default-button" href="<?php echo $locale->getEditPostUrl($objId); ?>">
                        <?php _e('Edit', 'polyglot'); ?>
                    </a>
                <?php else : ?>
                    <a class="button default-button" href="<?php echo $locale->getTranslatePostUrl($objId); ?>">
                        <?php _e('Translate', 'polyglot'); ?>
                    </a>
                <?php endif; ?>
            <?php else : ?>
                <?php debug($locale); ?>

                Pas un post
            <?php endif; ?>
        </td>
    </tr>
<?php $idx++; endforeach; ?>
</table>
