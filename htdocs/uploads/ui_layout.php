<?php
declare(strict_types=1);

/**
 * Modern HMS-style page chrome (toolbar + breadcrumbs + actions).
 *
 * @param array{
 *   subtitle?: string,
 *   breadcrumbs?: list<array{0: string, 1?: string|null}>,
 *   back?: string,
 *   primary?: array{label: string, url: string, icon?: string}|null,
 *   secondary?: list<array{label: string, url: string, icon?: string, class?: string}>
 * } $opts
 */
function hms_ui_page_header(string $title, array $opts = []): void
{
    $subtitle = (string) ($opts['subtitle'] ?? '');
    $breadcrumbs = $opts['breadcrumbs'] ?? [];
    $back = isset($opts['back']) ? (string) $opts['back'] : '';
    $primary = $opts['primary'] ?? null;
    $secondary = $opts['secondary'] ?? [];
    ?>
    <div class="hms-page-toolbar card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap align-items-start justify-content-between">
                <div class="mb-2 mb-md-0 pr-md-4 flex-grow-1" style="min-width: 200px;">
                    <?php if ($breadcrumbs !== []) { ?>
                    <nav aria-label="breadcrumb" class="mb-1">
                        <ol class="breadcrumb hms-breadcrumb mb-0 bg-transparent px-0 py-0">
                            <?php
                            $n = count($breadcrumbs);
                            foreach ($breadcrumbs as $i => $pair) {
                                $lab = (string) ($pair[0] ?? '');
                                $url = isset($pair[1]) ? (string) $pair[1] : '';
                                $isLast = $i === $n - 1;
                                if ($isLast || $url === '') {
                                    echo '<li class="breadcrumb-item active" aria-current="page">' . hms_h($lab) . '</li>';
                                } else {
                                    echo '<li class="breadcrumb-item"><a href="' . hms_h($url) . '">' . hms_h($lab) . '</a></li>';
                                }
                            }
                            ?>
                        </ol>
                    </nav>
                    <?php } ?>
                    <h1 class="hms-page-heading mb-0"><?php echo hms_h($title); ?></h1>
                    <?php if ($subtitle !== '') { ?>
                    <p class="text-muted small mb-0 mt-2"><?php echo hms_h($subtitle); ?></p>
                    <?php } ?>
                </div>
                <div class="d-flex flex-wrap align-items-center hms-toolbar-actions">
                    <?php
                    if ($back !== '') {
                        echo '<a href="' . hms_h($back) . '" class="btn btn-outline-secondary btn-sm mr-2 mb-2 mb-sm-0"><i class="fa fa-arrow-left mr-1"></i> Back</a>';
                    }
                    foreach ($secondary as $btn) {
                        $u = (string) ($btn['url'] ?? '');
                        $l = (string) ($btn['label'] ?? '');
                        $ic = (string) ($btn['icon'] ?? '');
                        $cl = (string) ($btn['class'] ?? 'btn-outline-primary');
                        if ($u === '' || $l === '') {
                            continue;
                        }
                        echo '<a href="' . hms_h($u) . '" class="btn ' . hms_h($cl) . ' btn-sm mr-2 mb-2 mb-sm-0">';
                        if ($ic !== '') {
                            echo '<i class="fa ' . hms_h($ic) . ' mr-1"></i> ';
                        }
                        echo hms_h($l) . '</a>';
                    }
                    if (is_array($primary) && !empty($primary['label']) && !empty($primary['url'])) {
                        $ic = (string) ($primary['icon'] ?? 'fa-plus');
                        echo '<a href="' . hms_h((string) $primary['url']) . '" class="btn btn-primary btn-sm mb-2 mb-sm-0">';
                        echo '<i class="fa ' . hms_h($ic) . ' mr-1"></i> ' . hms_h((string) $primary['label']);
                        echo '</a>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Module landing grid (EHR-style workspace tiles).
 *
 * @param list<array{
 *   title: string,
 *   description: string,
 *   url: string,
 *   icon?: string,
 *   badge?: string,
 *   disabled?: bool
 * }> $cards
 */
function hms_ui_module_hub(string $introHtml, array $cards): void
{
    if ($introHtml !== '') {
        echo '<p class="text-muted mb-4">' . $introHtml . '</p>';
    }
    echo '<div class="row hms-hub-grid">';
    foreach ($cards as $c) {
        $title = (string) ($c['title'] ?? '');
        $desc = (string) ($c['description'] ?? '');
        $url = (string) ($c['url'] ?? '');
        $icon = (string) ($c['icon'] ?? 'fa-folder-open');
        $badge = (string) ($c['badge'] ?? '');
        $disabled = !empty($c['disabled']);
        if ($title === '') {
            continue;
        }
        $tag = $disabled ? 'div' : 'a';
        $href = $disabled ? '' : ' href="' . hms_h($url) . '"';
        $muted = $disabled ? ' hms-hub-card--muted' : '';
        echo '<div class="col-md-6 col-lg-4 mb-4">';
        echo '<' . $tag . $href . ' class="card hms-hub-card border-0 shadow-sm h-100 text-decoration-none' . $muted . '">';
        echo '<div class="card-body d-flex flex-column">';
        echo '<div class="d-flex align-items-start justify-content-between mb-2">';
        echo '<span class="hms-hub-card-icon rounded d-flex align-items-center justify-content-center"><i class="fa ' . hms_h($icon) . '" aria-hidden="true"></i></span>';
        if ($badge !== '') {
            echo '<span class="badge badge-light text-muted small">' . hms_h($badge) . '</span>';
        }
        echo '</div>';
        echo '<h2 class="h6 font-weight-bold text-dark mb-2">' . hms_h($title) . '</h2>';
        echo '<p class="small text-muted mb-0 flex-grow-1">' . hms_h($desc) . '</p>';
        if (!$disabled) {
            echo '<span class="small text-primary font-weight-bold mt-3">Open <i class="fa fa-angle-right ml-1" aria-hidden="true"></i></span>';
        }
        echo '</div></' . $tag . '></div>';
    }
    echo '</div>';
}

function hms_ui_flash_toast_script(?string $message): void
{
    if ($message === null || $message === '') {
        return;
    }
    $safe = json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    if ($safe === false) {
        return;
    }
    echo '<script>document.addEventListener("DOMContentLoaded",function(){';
    echo 'var msg=' . $safe . ';';
    echo 'var d=document.createElement("div");';
    echo 'd.className="alert alert-success alert-dismissible fade show hms-flash-toast";';
    echo 'd.setAttribute("role","alert");';
    echo 'd.innerHTML=msg+\'<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>\';';
    echo 'var wrap=document.querySelector(".page-wrapper .content");';
    echo 'if(wrap){wrap.insertBefore(d,wrap.firstChild);}else{document.body.insertBefore(d,document.body.firstChild);}';
    echo 'setTimeout(function(){if(d.parentNode)d.parentNode.removeChild(d);},5000);';
    echo '});</script>';
}
