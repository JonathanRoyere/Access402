<?php

declare(strict_types=1);
?>
<div class="wrap access402-admin">
    <div class="access402-page-head">
        <div>
            <h1><?php esc_html_e('Access402', 'access402'); ?></h1>
            <p><?php esc_html_e('Sell access to content, files, and endpoints with global defaults, path-first rules, and a calm operational workflow.', 'access402'); ?></p>
        </div>
    </div>

    <nav class="nav-tab-wrapper">
        <?php foreach ($tabs as $slug => $label) : ?>
            <a class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(\Access402\Support\Helpers::admin_url(['tab' => $slug])); ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="access402-screen">
        <?php \Access402\Support\View::render('admin/' . $tab, $content); ?>
    </div>
</div>
