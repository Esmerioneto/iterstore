<?php
use Iter\Catalog\Model\WebHomeService;
use Iter\Core\Config;

$e = static fn ($v) => htmlspecialchars((string) $v);
$imageAccept = $e(implode(',', array_keys(\Iter\Eav\InputTypeRegistry::get('image')['accept'])));
$maxBytes = (int) Config::uploadMaxBytes();
$thumb = static fn (?string $file, string $label): string => $file
    ? '<a href="' . $e(Config::mediaUrl($file)) . '" target="_blank" data-image-popup="' . $e($label) . '"><img class="eav-file-preview web-thumb" src="' . $e(Config::mediaUrl($file)) . '" alt="' . $e($label) . '" data-image-preview></a>'
    : '<img class="eav-file-preview web-thumb eav-file-placeholder" src="/imagem/logo.png" alt="Sem foto" data-image-preview data-placeholder="/imagem/logo.png">';
// the categories of the website as <option>s; $under = only the subcategories of this one (the cards)
$options = static function (?int $selected, ?int $under = null) use ($categories, $e): string {
    $html = '<option value="">Escolha a categoria…</option>';
    foreach ($categories as $id => $c) {
        $html .= '<option value="' . (int) $id . '" data-parents="' . $e(implode(' ', $c['parents'])) . '"'
            . ($under !== null && !in_array($under, $c['parents'], true) ? ' hidden' : '')
            . ($id === $selected ? ' selected' : '') . '>' . $e($c['path']) . ($c['active'] ? '' : ' (inativa)') . '</option>';
    }

    return $html;
};
$card = static function (string $s, string $c, ?array $card, ?int $sectionCategory) use ($e, $options, $thumb, $imageAccept, $maxBytes): string {
    $inactive = $card !== null && !$card['active'];

    return '<li class="web-card" data-sort-item data-web-card>'
        . '<span class="mobile-handle" data-drag-handle title="Arrastar">⠿</span>'
        . '<button type="button" data-move="-1" aria-label="Mover para cima">↑</button><button type="button" data-move="1" aria-label="Mover para baixo">↓</button>'
        . ($card ? '<input type="hidden" name="sections[' . $s . '][cards][' . $c . '][id]" value="' . (int) $card['card_id'] . '">' : '')
        . '<select name="sections[' . $s . '][cards][' . $c . '][category_id]" required data-web-card-category aria-label="Subcategoria do card">' . $options($card['category_id'] ?? null, $sectionCategory) . '</select>'
        . $thumb($card['image'] ?? null, 'Card')
        . '<label class="web-file">Foto (900 × 1200)<input type="file" name="card_image[' . $s . '][' . $c . ']" accept="' . $imageAccept . '" data-image-input data-max-bytes="' . $maxBytes . '"' . ($card ? '' : ' required') . '></label>'
        . ($inactive ? ' <small>inativa, não aparece na loja</small>' : '')
        . '<button type="button" data-web-remove aria-label="Tirar o card">✕</button></li>';
};
$section = static function (string $s, ?array $section) use ($e, $options, $thumb, $card, $imageAccept, $maxBytes): string {
    $cards = '';
    foreach ($section['cards'] ?? [] as $i => $c) {
        $cards .= $card($s, 'c' . $i, $c, $section['category_id']);
    }
    $full = count($section['cards'] ?? []) >= WebHomeService::WEB_CARD_LIMIT;

    return '<li class="mobile-category web-section" data-sort-item data-web-section data-key="' . $e($s) . '">'
        . '<div class="mobile-bar">'
        . '<span class="mobile-handle" data-drag-handle title="Arrastar">⠿</span>'
        . '<button type="button" data-move="-1" aria-label="Mover para cima">↑</button><button type="button" data-move="1" aria-label="Mover para baixo">↓</button>'
        . ($section ? '<input type="hidden" name="sections[' . $s . '][id]" value="' . (int) $section['section_id'] . '">' : '')
        . '<select name="sections[' . $s . '][category_id]" required data-web-section-category aria-label="Categoria da seção">' . $options($section['category_id'] ?? null) . '</select>'
        . '<input type="text" name="sections[' . $s . '][title]" value="' . $e($section['title'] ?? '') . '" maxlength="' . WebHomeService::SECTION_TITLE_MAX . '" placeholder="Título (vazio = nome da categoria)" aria-label="Título da seção">'
        . ($section !== null && !$section['active'] ? ' <small>inativa, não aparece na loja</small>' : '')
        . '<button type="button" data-web-remove aria-label="Tirar a seção">✕</button>'
        . '</div>'
        . '<div class="web-section-body">'
        . '<p>' . $thumb($section['image'] ?? null, 'Seção') . '<label class="web-file">Foto da seção (1200 × 900, opcional)<input type="file" name="section_image[' . $s . ']" accept="' . $imageAccept . '" data-image-input data-max-bytes="' . $maxBytes . '"></label>'
        . (!empty($section['image']) ? ' <label class="config-check"><input type="checkbox" name="sections[' . $s . '][remove_image]" value="1"> Remover a foto</label>' : '')
        . '<br><small>Aparece no quadro da seção em "Escolha uma seção" (as 3 primeiras). Sem foto, a loja usa a do primeiro produto.</small></p>'
        . '<p class="web-section-options">'
        . '<label class="mobile-switch"><input type="hidden" name="sections[' . $s . '][show_banner]" value="0"><input type="checkbox" class="admin-switch" name="sections[' . $s . '][show_banner]" value="1"' . (!empty($section['show_banner']) ? ' checked' : '') . '> Mostrar banner</label>'
        . ' <small data-web-banner-note></small>'
        . ' <label>Exibir <input type="number" name="sections[' . $s . '][per_category]" value="' . (int) ($section['per_category'] ?? WebHomeService::DEFAULT_PER_CATEGORY) . '" min="0" max="' . WebHomeService::MAX_PER_CATEGORY . '" required data-web-limit> produtos</label>'
        . '</p>'
        . '<details data-web-products-box data-chosen="' . $e(json_encode($section['product_ids'] ?? [])) . '"><summary>Produtos <small data-web-summary></small></summary>'
        . '<p><small>Os escolhidos à mão vêm primeiro; os mais novos da categoria completam a quantidade. 0 = só os cards.</small></p>'
        . '<p data-web-count></p>'
        . '<ol class="mobile-products" data-sortable data-web-chosen></ol>'
        . '<p><small data-web-note></small></p>'
        . '<p><label>Adicionar <select data-web-add-select aria-label="Produto da categoria"></select></label> <button type="button" data-web-add-product>Adicionar</button></p>'
        . '</details>'
        . '<p><small>Cards: até ' . WebHomeService::WEB_CARD_LIMIT . ' subcategorias desta seção, cada uma com a foto vertical. Aparecem antes dos produtos.</small></p>'
        . '<ol class="web-cards" data-sortable data-web-cards>' . $cards . '</ol>'
        . '<p><button type="button" data-web-add-card' . ($full ? ' disabled' : '') . '>+ Card</button></p>'
        . '</div></li>';
};
// a slide of the carousel; $l = its key in the form
$slide = static function (string $l, ?array $slide) use ($e, $thumb, $imageAccept, $maxBytes): string {
    return '<li class="web-card web-slide" data-sort-item data-web-slide>'
        . '<span class="mobile-handle" data-drag-handle title="Arrastar">⠿</span>'
        . '<button type="button" data-move="-1" aria-label="Mover para cima">↑</button><button type="button" data-move="1" aria-label="Mover para baixo">↓</button>'
        . ($slide ? '<input type="hidden" name="slides[' . $l . '][id]" value="' . (int) $slide['slide_id'] . '">' : '')
        . $thumb($slide['image_desktop'] ?? null, 'Slide (desktop)')
        . '<label class="web-file">Desktop (1600 × 900)<input type="file" name="slide_desktop[' . $l . ']" accept="' . $imageAccept . '" data-image-input data-max-bytes="' . $maxBytes . '"></label>'
        . $thumb($slide['image_mobile'] ?? null, 'Slide (iPhone)')
        . '<label class="web-file">iPhone (1080 × 1350, opcional)<input type="file" name="slide_mobile[' . $l . ']" accept="' . $imageAccept . '" data-image-input data-max-bytes="' . $maxBytes . '"></label>'
        . (!empty($slide['image_mobile']) ? '<label class="config-check"><input type="checkbox" name="slides[' . $l . '][remove_mobile]" value="1"> Remover a do iPhone</label>' : '')
        . '<input type="text" name="slides[' . $l . '][title]" value="' . $e($slide['title'] ?? '') . '" maxlength="' . WebHomeService::SLIDE_TITLE_MAX . '" placeholder="Título (opcional)" aria-label="Título do slide">'
        . '<input type="text" name="slides[' . $l . '][url]" value="' . $e($slide['url'] ?? '') . '" maxlength="500" placeholder="Link (opcional): /promocoes" aria-label="Link do slide">'
        . '<button type="button" data-web-remove aria-label="Tirar o slide">✕</button></li>';
};
$h = $home;
?>
<section class="mobile-order web-home">
    <h2>Web — home da loja</h2>
<?php if (count($websites) > 1): ?>
    <form method="get" action="/admin/web/index">
        <label>Website
            <select name="website_id" data-autosubmit>
    <?php foreach ($websites as $id => $name): ?>
                <option value="<?= (int) $id ?>" <?= (int) $id === $website_id ? 'selected' : '' ?>><?= $e($name) ?></option>
    <?php endforeach; ?>
            </select>
        </label>
    </form>
<?php endif; ?>
    <p><small>O que a loja web mostra na página inicial (a consulta <code>webHome</code> da API). Textos são texto puro. Links: um endereço desta loja começando com <code>/</code> (ex.: <code>/promocoes</code>) ou um link <code>https://</code>. Prefira imagens WebP.</small></p>
    <form method="post" action="/admin/web/save" enctype="multipart/form-data" data-web-home data-card-limit="<?= WebHomeService::WEB_CARD_LIMIT ?>">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="website_id" value="<?= (int) $website_id ?>">

        <fieldset class="web-block">
            <legend>Aviso</legend>
            <p><label class="mobile-switch"><input type="hidden" name="announcement_enabled" value="0"><input type="checkbox" class="admin-switch" name="announcement_enabled" value="1" <?= $h['announcement_enabled'] ? 'checked' : '' ?>> Mostrar a barra de aviso</label></p>
            <p><label>Texto<br><input type="text" name="announcement_text" value="<?= $e($h['announcement_text']) ?>" maxlength="<?= WebHomeService::TEXTS['announcement_text'][1] ?>" class="web-wide" placeholder="Ex.: Frete grátis acima de R$ 199"></label></p>
            <p><label>Link (opcional)<br><input type="text" name="announcement_url" value="<?= $e($h['announcement_url']) ?>" maxlength="500" class="web-wide" placeholder="/promocoes"></label></p>
        </fieldset>

        <fieldset class="web-block">
            <legend>Topo da home</legend>
            <p>
<?php foreach (WebHomeService::TOP_MODES as $mode => $label): ?>
                <label class="config-check"><input type="radio" name="top_mode" value="<?= $mode ?>" <?= $h['top_mode'] === $mode ? 'checked' : '' ?> data-web-top> <?= $e($label) ?></label>
<?php endforeach; ?>
            </p>
            <p><small>Banner: a campanha abaixo (vídeo ou imagem, com textos e botão). Carrossel: até <?= WebHomeService::SLIDE_LIMIT ?> imagens que passam uma de cada vez.</small></p>
        </fieldset>

        <fieldset class="web-block" data-web-top-block="carousel" <?= $h['top_mode'] === 'carousel' ? '' : 'hidden' ?>>
            <legend>Carrossel</legend>
            <p><small>Até <?= WebHomeService::SLIDE_LIMIT ?> slides, nesta ordem. Arraste ⠿ ou use ↑ ↓. Cada um precisa da imagem de desktop; sem a do iPhone, o celular usa a de desktop.</small></p>
            <ol class="web-cards web-slides" data-sortable data-web-slides data-slide-limit="<?= WebHomeService::SLIDE_LIMIT ?>">
<?php foreach ($slides as $i => $sl): ?>
                <?= $slide('l' . $i, $sl) ?>
<?php endforeach; ?>
            </ol>
            <p><button type="button" data-web-add-slide <?= count($slides) >= WebHomeService::SLIDE_LIMIT ? 'disabled' : '' ?>>+ Slide</button></p>
            <template data-web-slide-template><?= $slide('__L__', null) ?></template>
        </fieldset>

        <fieldset class="web-block">
            <legend>Controles dos carrosséis</legend>
            <p><small>Valem para o carrossel do topo e para os banners das seções com dois ou mais banners.</small></p>
            <p>
                <label class="mobile-switch"><input type="hidden" name="carousel_arrows" value="0"><input type="checkbox" class="admin-switch" name="carousel_arrows" value="1" <?= $h['carousel_arrows'] ? 'checked' : '' ?>> Setas (anterior e próximo)</label>
                <label class="mobile-switch"><input type="hidden" name="carousel_dots" value="0"><input type="checkbox" class="admin-switch" name="carousel_dots" value="1" <?= $h['carousel_dots'] ? 'checked' : '' ?>> Indicadores (bolinhas)</label>
            </p>
            <p><label>Passar sozinho a cada <input type="number" name="carousel_autoplay" value="<?= (int) $h['carousel_autoplay'] ?>" min="0" max="<?= WebHomeService::AUTOPLAY_MAX ?>" required> segundos</label> <small>0 = só passa pelas setas, pelos indicadores ou arrastando. Para quando o mouse está em cima e com "reduzir movimento" ligado no aparelho.</small></p>
        </fieldset>

        <fieldset class="web-block" data-web-top-block="banner" <?= $h['top_mode'] === 'carousel' ? 'hidden' : '' ?>>
            <legend>Banner (campanha)</legend>
            <p><label class="mobile-switch"><input type="hidden" name="campaign_enabled" value="0"><input type="checkbox" class="admin-switch" name="campaign_enabled" value="1" <?= $h['campaign_enabled'] ? 'checked' : '' ?>> Mostrar a campanha</label></p>
            <p><label>Título<br><input type="text" name="campaign_title" value="<?= $e($h['campaign_title']) ?>" maxlength="<?= WebHomeService::TEXTS['campaign_title'][1] ?>" class="web-wide"></label></p>
            <p><label>Texto<br><textarea name="campaign_text" maxlength="<?= WebHomeService::TEXTS['campaign_text'][1] ?>" rows="3" class="web-wide"><?= $e($h['campaign_text']) ?></textarea></label></p>
            <p><label>Botão<br><input type="text" name="campaign_button" value="<?= $e($h['campaign_button']) ?>" maxlength="<?= WebHomeService::TEXTS['campaign_button'][1] ?>" placeholder="Ex.: Ver a coleção"></label></p>
            <p><label>Link do botão<br><input type="text" name="campaign_url" value="<?= $e($h['campaign_url']) ?>" maxlength="500" class="web-wide" placeholder="/colecao-verao"></label></p>
            <div class="web-files">
<?php foreach ([
    'campaign_image_desktop' => ['Imagem de desktop (1600 × 900)', 'Obrigatória com a campanha ligada; com vídeo, é a capa.'],
    'campaign_image_mobile' => ['Imagem do iPhone (1080 × 1350)', 'Opcional: sem ela, o celular usa a de desktop.'],
    'campaign_video' => ['Vídeo (MP4 ou WEBM, até ' . intdiv(WebHomeService::VIDEO_MAX_BYTES, 1048576) . ' MB)', 'Opcional: toca no lugar da imagem de desktop.'],
] as $field => [$label, $hint]): ?>
                <div class="web-file-field">
                    <strong><?= $e($label) ?></strong>
    <?php if ($field === 'campaign_video'): ?>
                    <?= $h[$field] ? '<p><a href="' . $e(Config::mediaUrl($h[$field])) . '" target="_blank">Ver o vídeo atual</a></p>' : '<p><small>Sem vídeo.</small></p>' ?>
                    <input type="file" name="<?= $field ?>" accept="<?= $e(implode(',', array_keys(WebHomeService::VIDEO_TYPES))) ?>">
    <?php else: ?>
                    <p><?= $thumb($h[$field], $label) ?></p>
                    <input type="file" name="<?= $field ?>" accept="<?= $imageAccept ?>" data-image-input data-max-bytes="<?= $maxBytes ?>">
    <?php endif; ?>
    <?php if ($h[$field]): ?>
                    <label class="config-check"><input type="checkbox" name="campaign_remove[<?= $field ?>]" value="1"> Remover</label>
    <?php endif; ?>
                    <small><?= $e($hint) ?></small>
                </div>
<?php endforeach; ?>
            </div>
        </fieldset>

        <fieldset class="web-block">
            <legend>Seções</legend>
            <p><label>Título das seções (opcional)<br><input type="text" name="sections_title" value="<?= $e($h['sections_title']) ?>" maxlength="<?= WebHomeService::TEXTS['sections_title'][1] ?>" class="web-wide" placeholder="Ex.: Escolha uma seção"></label><br>
                <small>Aparece acima do grid das 3 primeiras seções. Vazio: a loja não mostra título.</small></p>
            <p><small>Uma faixa por seção, nesta ordem; as 3 primeiras também formam o grid de seções. Arraste ⠿ ou use ↑ ↓. Categoria inativa não aparece na loja.</small></p>
<?php if (!$categories): ?>
            <p>Nenhuma categoria neste website: crie em Catálogo → Categorias.</p>
<?php else: ?>
            <ol class="mobile-categories web-sections" data-sortable data-web-sections>
    <?php foreach ($sections as $i => $s): ?>
                <?= $section('s' . $i, $s) ?>
    <?php endforeach; ?>
            </ol>
            <p><button type="button" data-web-add-section>+ Seção</button></p>
            <template data-web-section-template><?= $section('__S__', null) ?></template>
            <template data-web-card-template><?= $card('__S__', '__C__', null, null) ?></template>
            <script type="application/json" data-web-data><?= json_encode(['products' => (object) $products, 'banners' => (object) $banners], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
        </fieldset>
        <p><button type="submit" class="admin-button-primary">Salvar</button></p>
    </form>
</section>
