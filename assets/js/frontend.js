/* AM_VERSION_MARK 1.3.0 */
(function ($) {
// Show notices in the dedicated area below action buttons (instead of alert()).
function amShowNotice(type, message){
	var $box = $('#am-form-messages');
	if (!$box.length) { return alert(message); }
	var cls = 'am-notice';
	if (type === 'success') cls += ' am-notice--success';
	else if (type === 'error') cls += ' am-notice--error';
	else cls += ' am-notice--info';
	// Replace previous transient notices but keep server-rendered ones if present.
	$box.find('.am-notice.am-notice--js').remove();
	var $n = $('<div/>').addClass(cls + ' am-notice--js').text(message);
	$box.append($n);
	// Scroll into view if needed
	try { $n[0].scrollIntoView({behavior:'smooth', block:'nearest'}); } catch(e){}
}

	function fetchTerms(taxonomy, parentId, $select, optTransform) {
		$select.prop('disabled', true);
		$select.find('option').not(':first').remove();

		return $.get(AMFront.ajaxUrl, {
			action: 'am_get_terms',
			nonce: AMFront.nonce,
			taxonomy: taxonomy,
			parent: parentId
		}).then(function (resp) {
			if (resp && resp.success && resp.data && resp.data.terms) {
				resp.data.terms.forEach(function (t) {
					var opt = optTransform ? optTransform(t) : { value: t.id, text: t.name, 'data-parent': t.parent || 0 };
					var $o = $('<option>', { value: opt.value, text: opt.text });
					if (opt['data-parent'] !== undefined) { $o.attr('data-parent', String(opt['data-parent'])); }
					$select.append($o);
				});
				$select.prop('disabled', false);
			}
		});
	}

	function resetSelect($select) {
		$select.prop('disabled', true);
		$select.val('');
		$select.find('option').not(':first').remove();
	}

	$(document).ready(function () {
		var $orderHidden = $('#am_order'); // legacy
		var $division = $('#am_division');
		var $family = $('#am_family');
		var $genus = $('#am_genus');
		var $species = $('#am_species');
		var $filterDivision = $('#am_filter_division');
		var $filterFamily = $('#am_filter_family');
		var $filterGenus = $('#am_filter_genus');
		var $filterSpecies = $('#am_filter_species');

		var $infra = $('#am_infra');
		var $author = $('#am_author');

		function getSelectedParentAttr($select) {
			var $opt = $select.find('option:selected');
			var p = parseInt($opt.attr('data-parent') || '0', 10);
			return p || 0;
		}

		// Keep microform parents in sync (quick taxonomy creation without saving the post)
		function amSyncMicroParents() {
			var divisionVal = parseInt($division.val() || '0', 10);
			var familyVal = parseInt($family.val() || '0', 10);
			var genusVal  = parseInt($genus.val() || '0', 10);
			jQuery('#am_term_parent_family').val(divisionVal || 0);
			jQuery('#am_term_parent_genus').val(familyVal || 0);
			jQuery('#am_term_parent_species').val(genusVal || 0);
		}
		amSyncMicroParents();

		// Quick term creation (Editor+): allow adding terms directly from the front-end form.
		function postAddTerm(level, name, parent, done) {
			return $.post(AMFront.ajaxUrl, {
				action: 'am_add_term',
				context: 'frontend',
				nonce: AMFront.nonce,
				level: level,
				name: name,
				parent: parent || 0
			}).then(function (resp) {
				if (resp && resp.success && resp.data) {
					done(resp.data);
					return;
				}
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Errore.';
				amShowNotice('error', msg);
			});
		}

		// PRESELECT (edit form)
		var selD0 = parseInt(($division.attr('data-selected') || $filterDivision.attr('data-selected') || '0'), 10);
		var selF0 = parseInt(($family.attr('data-selected') || $filterFamily.attr('data-selected') || '0'), 10);
		var selG0 = parseInt(($genus.attr('data-selected') || $filterGenus.attr('data-selected') || '0'), 10);
		var selS0 = parseInt(($species.attr('data-selected') || $filterSpecies.attr('data-selected') || '0'), 10);
		var selI0 = parseInt($infra.attr('data-selected') || '0', 10);
		var selA0 = parseInt($author.attr('data-selected') || '0', 10);

		// Load Forma/Varietà (global) and Autore (global)
		if ($infra && $infra.length) {
			fetchTerms(AMFront.taxInfra, 0, $infra).then(function(){
				$infra.prop('disabled', false);
				if (selI0) { $infra.val(String(selI0)).trigger('change'); selI0 = 0; }
			});
		}
		if ($author && $author.length) {
			fetchTerms(AMFront.taxAuthor, 0, $author).then(function(){
				$author.prop('disabled', false);
				if (selA0) { $author.val(String(selA0)).trigger('change'); selA0 = 0; }
			});
		}


		// Load divisions -> families -> genera -> species (submit/edit)
		if ($division.length) {
			fetchTerms(AMFront.taxClass, 0, $division).then(function () {
				$division.prop('disabled', false);
				if (selD0) { $division.val(String(selD0)); }
				$division.trigger('change');
			});
		}

		$(document).on('change', '#am_division', function () {
			var divisionId = parseInt($division.val() || '0', 10);
			resetSelect($family); resetSelect($genus); resetSelect($species);
			if (!divisionId) { amSyncMicroParents(); return; }
			fetchTerms(AMFront.taxClass, divisionId, $family).then(function () {
				$family.prop('disabled', false);
				if (selF0) { $family.val(String(selF0)); }
				$family.trigger('change');
				selF0 = 0;
			});
			amSyncMicroParents();
		});

		$(document).on('change', '#am_family', function () {
			var familyId = parseInt($family.val() || '0', 10);
			resetSelect($genus); resetSelect($species);
			if (!familyId) { amSyncMicroParents(); return; }
			fetchTerms(AMFront.taxClass, familyId, $genus).then(function () {
				$genus.prop('disabled', false);
				if (selG0) { $genus.val(String(selG0)); }
				$genus.trigger('change');
				selG0 = 0;
			});
			amSyncMicroParents();
		});

		$(document).on('change', '#am_genus', function () {
			var genusId = parseInt($genus.val() || '0', 10);
			resetSelect($species);
			if (!genusId) { amSyncMicroParents(); return; }
			fetchTerms(AMFront.taxClass, genusId, $species).then(function () {
				$species.prop('disabled', false);
				if (selS0) { $species.val(String(selS0)); }
				selS0 = 0;
			});
			amSyncMicroParents();
		});

		// Archive filters: division -> family -> genus -> species
		if ($filterDivision.length) {
			fetchTerms(AMFront.taxClass, 0, $filterDivision).then(function(){
				$filterDivision.prop('disabled', false);
				if (selD0) { $filterDivision.val(String(selD0)); }
				$filterDivision.trigger('change');
			});
		}
		$(document).on('change', '#am_filter_division', function(){
			var divisionId = parseInt($filterDivision.val() || '0', 10);
			resetSelect($filterFamily); resetSelect($filterGenus); resetSelect($filterSpecies);
			$filterFamily.find('option:first').text('Tutte');
			$filterGenus.find('option:first').text('Tutti');
			$filterSpecies.find('option:first').text('Tutte');
			if (!divisionId) { return; }
			fetchTerms(AMFront.taxClass, divisionId, $filterFamily).then(function(){
				$filterFamily.prop('disabled', false);
				if (selF0) { $filterFamily.val(String(selF0)); }
				$filterFamily.trigger('change');
				selF0 = 0;
			});
		});
		$(document).on('change', '#am_filter_family', function(){
			var familyId = parseInt($filterFamily.val() || '0', 10);
			resetSelect($filterGenus); resetSelect($filterSpecies);
			$filterGenus.find('option:first').text('Tutti');
			$filterSpecies.find('option:first').text('Tutte');
			if (!familyId) { return; }
			fetchTerms(AMFront.taxClass, familyId, $filterGenus).then(function(){
				$filterGenus.prop('disabled', false);
				if (selG0) { $filterGenus.val(String(selG0)); }
				$filterGenus.trigger('change');
				selG0 = 0;
			});
		});
		$(document).on('change', '#am_filter_genus', function(){
			var genusId = parseInt($filterGenus.val() || '0', 10);
			resetSelect($filterSpecies);
			$filterSpecies.find('option:first').text('Tutte');
			if (!genusId) { return; }
			fetchTerms(AMFront.taxClass, genusId, $filterSpecies).then(function(){
				$filterSpecies.prop('disabled', false);
				if (selS0) { $filterSpecies.val(String(selS0)); }
				selS0 = 0;
			});
		});


		
		// AM 1.5.50 - restore full taxonomy hierarchy from return URL after "Torna ai risultati".
		function amRestoreArchiveFilters1550(){
			var urlObj = new URL(window.location.href);
			if (!urlObj.searchParams.has('am_restore')) { return; }

			var params = urlObj.searchParams;
			var div = parseInt(params.get('am_division') || '0', 10);
			var fam = parseInt(params.get('am_family') || '0', 10);
			var gen = parseInt(params.get('am_genus') || '0', 10);
			var spe = parseInt(params.get('am_species') || '0', 10);
			if (!div && !fam && !gen && !spe) { return; }

			function setIfPossible($el, value, triggerChange){
				if (!$el || !$el.length || !value) { return false; }
				var s = String(value);
				var hasOption = $el.find('option[value="' + s.replace(/"/g, '\\"') + '"]').length > 0;
				if (!hasOption) { return false; }
				$el.val(s);
				if (triggerChange) { $el.trigger('change'); }
				return true;
			}

			function stepDivision(){
				if (div && setIfPossible($filterDivision, div, true)) {
					window.setTimeout(stepFamily, 650);
				} else {
					stepFamily();
				}
			}
			function stepFamily(){
				if (fam && setIfPossible($filterFamily, fam, true)) {
					window.setTimeout(stepGenus, 650);
				} else {
					stepGenus();
				}
			}
			function stepGenus(){
				if (gen && setIfPossible($filterGenus, gen, true)) {
					window.setTimeout(stepSpecies, 650);
				} else {
					stepSpecies();
				}
			}
			function stepSpecies(){
				if (spe) {
					setIfPossible($filterSpecies, spe, false);
				}
				try { document.dispatchEvent(new CustomEvent('am:archive-updated', { bubbles: true })); } catch(e) {}
			}

			window.setTimeout(stepDivision, 800);
			window.setTimeout(stepDivision, 1800);
		}
		amRestoreArchiveFilters1550();

// Keep parents in sync also when species changes (for micro-add panels)
		$(document).on('change', '#am_species', function(){ amSyncMicroParents(); });



		if (AMFront && AMFront.canAddTerms) {
			$(document).on('click', '.am-add-term', function () {
				var level = $(this).data('am-level');
				var $panel = $('.am-add-term__panel[data-am-panel="' + level + '"]');
				$panel.prop('hidden', !$panel.prop('hidden'));
				if (!$panel.is('[hidden]')) {
					$panel.find('input').first().focus();
				}
			});

			// FAMILY
			$(document).on('submit', '#am_add_term_family_form', function (e) {
				e.preventDefault();
				var name = String($('[data-am-input="family"]').val() || '').trim();
				var parent = parseInt($division.val() || '0', 10);
				postAddTerm('family', name, parent, function (t) {
					var $o = $('<option>', { value: t.id, text: t.name }).attr('data-parent', String(parent));
					$family.append($o).val(String(t.id)).trigger('change');
					$('[data-am-input="family"]').val('');
				});
			});

			// GENUS
			$(document).on('submit', '#am_add_term_genus_form', function (e) {
				e.preventDefault();
				var name = String($('[data-am-input="genus"]').val() || '').trim();
				var parent = parseInt($family.val() || '0', 10);
				postAddTerm('genus', name, parent, function (t) {
					$genus.append($('<option>', { value: t.id, text: t.name })).val(String(t.id)).trigger('change');
					$('[data-am-input="genus"]').val('');
				});
			});

			// SPECIES
			$(document).on('submit', '#am_add_term_species_form', function (e) {
				e.preventDefault();
				var name = String($('[data-am-input="species"]').val() || '').trim();
				var parent = parseInt($genus.val() || '0', 10);
				postAddTerm('species', name, parent, function (t) {
					$species.append($('<option>', { value: t.id, text: t.name })).val(String(t.id));
					$('[data-am-input="species"]').val('');
				});
			});

			// INFRA (Forma/Varietà)
			$(document).on('submit', '#am_add_term_infra_form', function (e) {
				e.preventDefault();
				var name = String($('[data-am-input="infra"]').val() || '').trim();
				postAddTerm('infra', name, 0, function (t) {
					$infra.append($('<option>', { value: t.id, text: t.name })).val(String(t.id));
					$('[data-am-input="infra"]').val('');
				});
			});

			// AUTHOR
			$(document).on('submit', '#am_add_term_author_form', function (e) {
				e.preventDefault();
				var name = String($('[data-am-input="author"]').val() || '').trim();
				postAddTerm('author', name, 0, function (t) {
					$author.append($('<option>', { value: t.id, text: t.name })).val(String(t.id));
					$('[data-am-input="author"]').val('');
				});
			});
		}
	
		// MEDIA PICKERS (featured + gallery) for front-end submit/edit forms.
		var featuredFrame = null;
		var galleryFrame = null;
		var pdfFrame = null;

		function renderFeatured(id, url) {
			$('#am_featured_id').val(String(id || ''));
			var $p = $('#am_featured_preview');
			if (!$p.length) return;
			if (!id || !url) { $p.empty(); return; }
			$p.html('<img src="' + String(url) + '" alt="" style="max-width:220px;height:auto;border-radius:8px;" />');
		}

		function renderGallery(ids, urls) {
			$('#am_gallery_ids').val((ids || []).join(','));
			var $p = $('#am_gallery_preview');
			if (!$p.length) return;
			$p.empty();
			if (!ids || !ids.length) return;
			(urls || []).forEach(function(u){
				$p.append('<img src="' + String(u) + '" alt="" style="width:90px;height:90px;object-fit:cover;border-radius:8px;margin:4px;" />');
			});
		}

		function renderPdf(id, name, url) {
			$('#am_pdf_id').val(String(id || ''));
			var $p = $('#am_pdf_preview');
			if (!$p.length) return;
			$p.empty();
			if (!id) return;
			var safeName = String(name || 'PDF');
			var safeUrl  = String(url || '');
			var html = '<div style="display:flex;align-items:center;gap:10px;">'
				+ '<span style="display:inline-block;padding:4px 8px;border:1px solid #ddd;border-radius:999px;font-size:12px;">PDF</span>'
				+ '<span style="font-size:13px;">' + safeName + '</span>';
			if (safeUrl) {
				html += ' <a href="' + safeUrl + '" target="_blank" rel="noopener" style="font-size:12px;">Apri</a>';
			}
			html += '<button type="button" class="button" id="am_clear_pdf" style="margin-left:auto;">Rimuovi</button>';
			html += '</div>';
			$p.html(html);
		}

		// Clear PDF selection
		$(document).on('click', '#am_clear_pdf', function (e) {
			e.preventDefault();
			$('#am_pdf_id').val('');
			var $p = $('#am_pdf_preview');
			if ($p.length) { $p.empty(); }
		});

		$(document).on('click', '#am_pick_featured', function (e) {
			e.preventDefault();
			if (typeof wp === 'undefined' || !wp.media) {
				amShowNotice('error', 'Media library non disponibile. Verifica che WordPress carichi i media sul front-end.');
				return;
			}
			if (featuredFrame) { featuredFrame.open(); return; }

			featuredFrame = wp.media({
				title: 'Seleziona immagine anteprima',
				library: { type: 'image' },
				button: { text: 'Usa questa immagine' },
				multiple: false
			});

			featuredFrame.on('select', function () {
				var att = featuredFrame.state().get('selection').first().toJSON();
				renderFeatured(att.id, (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url);
			});

			featuredFrame.open();
		});

		$(document).on('click', '#am_pick_gallery', function (e) {
			e.preventDefault();
			if (typeof wp === 'undefined' || !wp.media) {
				amShowNotice('error', 'Media library non disponibile. Verifica che WordPress carichi i media sul front-end.');
				return;
			}
			if (galleryFrame) { galleryFrame.open(); return; }

			galleryFrame = wp.media({
				title: 'Seleziona immagini galleria',
				library: { type: 'image' },
				button: { text: 'Usa queste immagini' },
				multiple: true
			});

			galleryFrame.on('select', function () {
				var sel = galleryFrame.state().get('selection');
				var ids = [];
				var urls = [];
				sel.each(function (m) {
					var att = m.toJSON();
					ids.push(att.id);
					urls.push((att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url);
				});
				renderGallery(ids, urls);
			});

			galleryFrame.open();
		});

		$(document).on('click', '#am_pick_pdf', function (e) {
			e.preventDefault();
			if (typeof wp === 'undefined' || !wp.media) {
				amShowNotice('error', 'Media library non disponibile. Verifica che WordPress carichi i media sul front-end.');
				return;
			}
			if (pdfFrame) { pdfFrame.open(); return; }

			pdfFrame = wp.media({
				title: 'Seleziona PDF',
				library: { type: 'application/pdf' },
				button: { text: 'Usa questo PDF' },
				multiple: false
			});

			pdfFrame.on('select', function () {
				var att = pdfFrame.state().get('selection').first().toJSON();
				renderPdf(att.id, att.filename || att.title || 'PDF', att.url || '');
			});

			pdfFrame.open();
		});

		// --- Lightweight Lightbox with navigation (single entry gallery) ---
		 (function initLightbox(){
			try { // initLightbox

			var $items = $('.am-lightbox-item');
			if (!$items.length) return;

			var urls = [];
			$items.each(function(){
				var href = $(this).attr('href');
				if (href) urls.push(href);
			});
			if (!urls.length) return;

			// Build DOM once
			var $lb = $('<div class="am-lightbox" role="dialog" aria-modal="true" aria-label="Immagine"></div>');
			var $back = $('<div class="am-lightbox__backdrop" aria-hidden="true"></div>');
			var $dlg = $('<div class="am-lightbox__dialog"></div>');
			var $img = $('<img class="am-lightbox__img" alt="" />');
			var $close = $('<button type="button" class="am-lightbox__close" aria-label="Chiudi"><span class="am-ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" focusable="false" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg></span></button>');
			var $prev = $('<button type="button" class="am-lightbox__nav am-lightbox__nav--prev" aria-label="Immagine precedente"><span class="am-ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" focusable="false" aria-hidden="true"><path d="M15 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>');
			var $next = $('<button type="button" class="am-lightbox__nav am-lightbox__nav--next" aria-label="Immagine successiva"><span class="am-ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" focusable="false" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>');

			$dlg.append($prev, $img, $next, $close);
			$lb.append($back, $dlg);
			$('body').append($lb);

			var idx = 0;
			function clamp(i){
				var n = urls.length;
				if (!n) return 0;
				return (i + n) % n;
			}
			function openAt(i){
				idx = clamp(i);
				$img.attr('src', urls[idx]);
				$lb.addClass('is-open');
				$('html').addClass('am-lightbox-open');
			}
			function close(){
				$lb.removeClass('is-open');
				$('html').removeClass('am-lightbox-open');
				$img.attr('src','');
			}
			function prev(){ openAt(idx - 1); }
			function next(){ openAt(idx + 1); }

			$(document).on('click', '.am-lightbox-item', function(e){
				e.preventDefault();
				var href = $(this).attr('href');
				var i = urls.indexOf(href);
				openAt(i >= 0 ? i : 0);
			});
			$back.on('click', close);
			$close.on('click', close);
			$prev.on('click', function(e){ e.preventDefault(); prev(); });
			$next.on('click', function(e){ e.preventDefault(); next(); });
			$(document).on('keydown', function(e){
				if (!$lb.hasClass('is-open')) return;
				if (e.key === 'Escape') { close(); }
				if (e.key === 'ArrowLeft') { prev(); }
				if (e.key === 'ArrowRight') { next(); }
			});
			} catch(e){ /* lightbox failed, fall back to normal links */ }
		})();



	// Save review button (admin only)
	function formatNow() {
		try {
			var d = new Date();
			return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0') + ' ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0') + ':' + String(d.getSeconds()).padStart(2,'0');
		} catch(e){ return ''; }
	}

	$(document).on('click', '#am-save-review-btn', function(e){
		e.preventDefault();
		if (typeof AMFront === 'undefined' || !AMFront.canReview) { return; }

		var $btn = $(this);
		var $preview = $('#am-review-preview');
		var $reviewTop = $('#am-review-top');

		// If we are on edit form, save via AJAX (post exists)
		var postId = parseInt($btn.data('post-id') || 0, 10);
		if (postId > 0) {
			$btn.prop('disabled', true);
			$.post(AMFront.ajaxUrl, {
				action: 'am_save_review',
				nonce: AMFront.nonce,
				post_id: postId
			}).done(function(resp){
				if (resp && resp.success && resp.data) {
					$preview.text(resp.data.display || ('Revisione: ' + (resp.data.reviewer||'') + ' (' + (resp.data.datetime||'') + ')'));
					$preview.show();
					if ($reviewTop.length){ $reviewTop.text(resp.data.display ? resp.data.display.replace('Ultima revisione:', 'Revisione:') : ('Revisione: ' + (resp.data.reviewer||'') + ' (' + (resp.data.datetime||'') + ')')); $reviewTop.show(); }
					amShowNotice('success', 'Revisione salvata.');
				} else {
					amShowNotice('error', (resp && resp.data && resp.data.message) ? resp.data.message : 'Impossibile salvare la revisione.');
				}
			}).fail(function(){
				amShowNotice('error', 'Impossibile salvare la revisione.');
			}).always(function(){
				$btn.prop('disabled', false);
			});
			return;
		}

		// New insert form (post non ancora creato): la revisione è consentita solo
		// dopo il primo salvataggio con "Invia scheda".
		amShowNotice('error', 'Operazione consentita solo dopo il primo salvataggio con “Invia scheda”.');
	});

});
})(jQuery);



(function(){
	function initAMRandomCarousels(scope){
		var root = scope && scope.querySelectorAll ? scope : document;
		var carousels = root.querySelectorAll('[data-am-carousel]');
		if(!carousels.length){ return; }

		carousels.forEach(function(carousel){
			var existing = carousel.__amCarouselInstance;
			if (existing && typeof existing.refresh === 'function') {
				existing.refresh();
				return;
			}

			var track = carousel.querySelector('[data-am-carousel-track]');
			if(!track){ return; }
			var slides = Array.prototype.slice.call(carousel.querySelectorAll('[data-am-carousel-slide]'));
			if(slides.length <= 1){ return; }

			var dotsWrap = carousel.querySelector('[data-am-carousel-dots]');
			var prev = carousel.querySelector('[data-am-carousel-prev]');
			var next = carousel.querySelector('[data-am-carousel-next]');
			var index = 0;
			var timer = null;
			var autoplayEnabled = carousel.getAttribute('data-am-autoplay') === '1';
			var intervalSeconds = parseInt(carousel.getAttribute('data-am-interval') || '4', 10);
			var configuredVisible = parseInt(carousel.getAttribute('data-am-visible') || '3', 10);

			if (isNaN(intervalSeconds)) { intervalSeconds = 4; }
			if (intervalSeconds < 2) { intervalSeconds = 2; }
			if (intervalSeconds > 20) { intervalSeconds = 20; }
			if (isNaN(configuredVisible)) { configuredVisible = 3; }
			if (configuredVisible < 1) { configuredVisible = 1; }
			if (configuredVisible > 3) { configuredVisible = 3; }

			var configuredRows = Math.max(1, Math.min(4, parseInt(carousel.dataset.amRows, 10) || 1));
			var controls = carousel.querySelector('.am-random-funghi-carousel__controls');
			var interval = intervalSeconds * 1000;
			var dots = [];
			var visibleCount = 1;
			var maxIndex = Math.max(0, slides.length - 1);

			function getVisibleCount(){
				var ww = window.innerWidth || document.documentElement.clientWidth || 1200;
				if (ww <= 639) { return 1; }
				if (ww <= 979) { return Math.min(2, configuredVisible, slides.length); }
				return Math.min(configuredVisible, slides.length);
			}

			function buildDots(){
				if (!dotsWrap) { return; }
				dotsWrap.innerHTML = '';
				dots = [];
				var positions = maxIndex + 1;
				for (var i = 0; i < positions; i++) {
					var dot = document.createElement('button');
					dot.type = 'button';
					dot.className = 'am-random-funghi-carousel__dot' + (i === index ? ' is-active' : '');
					dot.setAttribute('data-am-carousel-dot', String(i));
					dot.setAttribute('aria-label', 'Vai alla posizione ' + (i + 1));
					dot.addEventListener('click', function(){
						var target = parseInt(this.getAttribute('data-am-carousel-dot') || '0', 10);
						goTo(target);
						start();
					});
					dotsWrap.appendChild(dot);
					dots.push(dot);
				}
			}

			function applyLayout(){
				visibleCount = getVisibleCount();
				var rows = Math.min(configuredRows, Math.ceil(slides.length / visibleCount));
				var columns = Math.ceil(slides.length / rows);
				maxIndex = Math.max(0, columns - visibleCount);
				carousel.classList.toggle('am-random-funghi-carousel--rows', rows > 1);
				carousel.style.setProperty('--am-rows', String(rows));
				if (controls) { controls.hidden = maxIndex === 0; }
				if (index > maxIndex) {
					index = maxIndex;
				}
				carousel.style.setProperty('--am-visible', String(visibleCount));
				buildDots();
				render();
			}

			function getStepWidth(){
				if (!slides.length) { return 0; }
				return slides[0].getBoundingClientRect().width + parseFloat(window.getComputedStyle(track).columnGap || window.getComputedStyle(track).gap || '0');
			}

			function render(){
				var step = getStepWidth();
				track.style.transform = 'translateX(-' + (index * step) + 'px)';
				dots.forEach(function(dot, i){
					dot.classList.toggle('is-active', i === index);
				});
				if (prev) { prev.disabled = maxIndex <= 0; }
				if (next) { next.disabled = maxIndex <= 0; }
			}

			function goTo(newIndex){
				if (newIndex < 0) {
					index = maxIndex;
				} else if (newIndex > maxIndex) {
					index = 0;
				} else {
					index = newIndex;
				}
				render();
			}

			function start(){
				stop();
				if(!autoplayEnabled || maxIndex <= 0){ return; }
				timer = window.setInterval(function(){ goTo(index + 1); }, interval);
			}

			function stop(){
				if(timer){
					window.clearInterval(timer);
					timer = null;
				}
			}

			if(prev){
				prev.addEventListener('click', function(){ goTo(index - 1); start(); });
			}
			if(next){
				next.addEventListener('click', function(){ goTo(index + 1); start(); });
			}

			carousel.addEventListener('mouseenter', stop);
			carousel.addEventListener('mouseleave', start);
			carousel.addEventListener('focusin', stop);
			carousel.addEventListener('focusout', start);

			var resizeTimer = null;
			function onResize(){
				if (resizeTimer) { window.clearTimeout(resizeTimer); }
				resizeTimer = window.setTimeout(function(){
					applyLayout();
					start();
				}, 90);
			}
			window.addEventListener('resize', onResize);

			carousel.__amCarouselInstance = {
				refresh: function(){
					applyLayout();
					start();
				}
			};

			applyLayout();
			start();
		});
	}

	function boot(){
		initAMRandomCarousels(document);
	}

	if(document.readyState === 'loading'){
		document.addEventListener('DOMContentLoaded', boot);
	}else{
		boot();
	}

	if (window.jQuery) {
		jQuery(window).on('elementor/frontend/init', function(){
			if (window.elementorFrontend && elementorFrontend.hooks) {
				elementorFrontend.hooks.addAction('frontend/element_ready/global', function($scope){
					initAMRandomCarousels($scope && $scope[0] ? $scope[0] : document);
				});
			}
		});
	}
})();



/* AM UX submit form 1.5.26 - guided descriptive template */
(function(){
  function ready(fn){
    if(document.readyState !== 'loading'){ fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function getTemplateText(){
    var node = document.getElementById('am-macro-template-json');
    if(!node){ return ''; }
    try {
      var lines = JSON.parse(node.textContent || '[]');
      if(Array.isArray(lines)){
        return lines.join("\n\n");
      }
    } catch(e) {}
    return [
      'Cappello: ',
      'Lamelle: ',
      'Gambo: ',
      'Carne: ',
      'Odore: ',
      'Sapore: ',
      'Habitat: ',
      'Osservazioni: '
    ].join("\n\n");
  }

  function insertIntoEditor(editorId, text){
    if(!text){ return; }

    if(window.tinyMCE && window.tinyMCE.get && window.tinyMCE.get(editorId)){
      var ed = window.tinyMCE.get(editorId);
      var current = (ed.getContent({format:'text'}) || '').trim();
      if(current.length > 0){
        var ok = window.confirm('Il campo descrizione macroscopica contiene già del testo. Vuoi aggiungere la traccia in fondo?');
        if(!ok){ return; }
        ed.setContent(ed.getContent() + '<p></p>' + text.replace(/\n\n/g, '</p><p>').replace(/\n/g, '<br>'));
      } else {
        ed.setContent('<p>' + text.replace(/\n\n/g, '</p><p>').replace(/\n/g, '<br>') + '</p>');
      }
      ed.focus();
      ed.fire('change');
      return;
    }

    var ta = document.getElementById(editorId);
    if(!ta){ return; }
    var currentValue = (ta.value || '').trim();
    if(currentValue.length > 0){
      var ok2 = window.confirm('Il campo descrizione macroscopica contiene già del testo. Vuoi aggiungere la traccia in fondo?');
      if(!ok2){ return; }
      ta.value = ta.value + "\n\n" + text;
    } else {
      ta.value = text;
    }
    ta.focus();
    ta.dispatchEvent(new Event('input', {bubbles:true}));
    ta.dispatchEvent(new Event('change', {bubbles:true}));
  }

  ready(function(){
    var btn = document.getElementById('am-insert-macro-template');
    if(!btn){ return; }
    btn.addEventListener('click', function(){
      insertIntoEditor('am_content', getTemplateText());
    });
  });
})();


/* AM UX submit form 1.5.27 - media preview/status */
(function(){
  function ready(fn){
    if(document.readyState !== 'loading'){ fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function val(id){
    var el = document.getElementById(id);
    return el ? (el.value || '').trim() : '';
  }

  function setVal(id, value){
    var el = document.getElementById(id);
    if(!el){ return; }
    el.value = value || '';
    el.dispatchEvent(new Event('input', {bubbles:true}));
    el.dispatchEvent(new Event('change', {bubbles:true}));
  }

  function setStatus(id, text, ok){
    var el = document.getElementById(id);
    if(!el){ return; }
    el.textContent = text;
    el.classList.toggle('is-ok', !!ok);
    el.classList.toggle('is-empty', !ok);
  }

  function countIds(value){
    if(!value){ return 0; }
    return value.split(/[,\s]+/).filter(function(v){ return !!v && /^\d+$/.test(v); }).length;
  }

  function enhancePdfPreview(){
    var pdfId = val('am_pdf_id');
    var preview = document.getElementById('am_pdf_preview');
    if(pdfId && preview && !preview.textContent.trim() && !preview.querySelector('*')){
      preview.innerHTML = '<span class="am-media-preview__pdf">PDF selezionato ID ' + pdfId + '</span>';
    }
  }

  function updateMediaStatus(){
    var featured = val('am_featured_id');
    var gallery = val('am_gallery_ids');
    var pdf = val('am_pdf_id');

    setStatus('am-media-status-featured', featured ? 'Immagine di anteprima selezionata (ID ' + featured + ').' : 'Nessuna immagine di anteprima selezionata.', !!featured);

    var gcount = countIds(gallery);
    setStatus('am-media-status-gallery', gcount ? 'Galleria selezionata: ' + gcount + ' immagine/i.' : 'Nessuna immagine in galleria selezionata.', !!gcount);

    setStatus('am-media-status-pdf', pdf ? 'PDF selezionato (ID ' + pdf + ').' : 'Nessun PDF selezionato.', !!pdf);
    enhancePdfPreview();
  }

  function clearMedia(kind){
    if(kind === 'featured'){
      setVal('am_featured_id', '');
      var fp = document.getElementById('am_featured_preview');
      if(fp){ fp.innerHTML = ''; }
    }
    if(kind === 'gallery'){
      setVal('am_gallery_ids', '');
      var gp = document.getElementById('am_gallery_preview');
      if(gp){ gp.innerHTML = ''; }
    }
    if(kind === 'pdf'){
      setVal('am_pdf_id', '');
      var pp = document.getElementById('am_pdf_preview');
      if(pp){ pp.innerHTML = ''; }
    }
    updateMediaStatus();
  }

  ready(function(){
    if(!document.getElementById('am-main-form')){ return; }

    ['am_featured_id','am_gallery_ids','am_pdf_id'].forEach(function(id){
      var el = document.getElementById(id);
      if(el){
        el.addEventListener('input', updateMediaStatus);
        el.addEventListener('change', updateMediaStatus);
      }
    });

    document.addEventListener('click', function(e){
      var btn = e.target && e.target.closest ? e.target.closest('[data-am-clear-media]') : null;
      if(btn){
        e.preventDefault();
        clearMedia(btn.getAttribute('data-am-clear-media'));
        return;
      }

      // Media modal handlers usually update hidden fields after selection.
      window.setTimeout(updateMediaStatus, 350);
      window.setTimeout(updateMediaStatus, 1000);
    });

    updateMediaStatus();
    window.setTimeout(updateMediaStatus, 800);
    window.setInterval(updateMediaStatus, 2500);
  });
})();


/* AM 1.5.50 - contextual return URL with full taxonomy filter state */
(function(){
  function ready(fn){
    if(document.readyState !== 'loading'){ fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function isInternalAMFungoLink(a){
    if(!a || !a.href){ return false; }
    try {
      var u = new URL(a.href, window.location.href);
      if(u.origin !== window.location.origin){ return false; }
      return u.pathname.indexOf('/funghi/') !== -1;
    } catch(e) {
      return false;
    }
  }

  function pageLooksLikeAMListing(){
    return !!(
      document.querySelector('.am-archive') ||
      document.querySelector('.am-search-page') ||
      document.querySelector('.am-search-results') ||
      document.querySelector('.am-archive-tree') ||
      document.querySelector('.am-archive__results') ||
      document.querySelector('.am-results') ||
      document.querySelector('[data-am-archive]') ||
      document.querySelector('[data-am-search]')
    );
  }

  function readFieldValue(selectors){
    for(var i=0; i<selectors.length; i++){
      var el = document.querySelector(selectors[i]);
      if(el && typeof el.value !== 'undefined' && String(el.value).trim() !== ''){
        return String(el.value).trim();
      }
    }
    return '';
  }

  function buildReturnURL(){
    var u = new URL(window.location.href);

    // Preserve common AM search/filter fields even when the archive updates via AJAX
    // without changing the browser URL.
    var search = readFieldValue([
      'input[name="am_search"]',
      'input[name="am_s"]',
      'input[name="am_q"]',
      'input[name="s"]',
      '.am-search-page input[type="search"]',
      '.am-search-page input[type="text"]',
      '.am-archive input[type="search"]',
      '.am-archive input[type="text"]'
    ]);

    var division = readFieldValue([
      'select[name="am_division"]',
      'select#am_filter_division',
      'input[name="am_division"]',
      '[data-am-division]'
    ]);

    var family = readFieldValue([
      'select[name="am_family"]',
      'select#am_filter_family',
      'input[name="am_family"]',
      '[data-am-family]'
    ]);

    var genus = readFieldValue([
      'select[name="am_genus"]',
      'select#am_filter_genus',
      'input[name="am_genus"]',
      '[data-am-genus]'
    ]);

    var species = readFieldValue([
      'select[name="am_species"]',
      'select#am_filter_species',
      'input[name="am_species"]',
      '[data-am-species]'
    ]);

    if(search){ u.searchParams.set('am_search', search); }
    if(division){ u.searchParams.set('am_division', division); }
    if(family){ u.searchParams.set('am_family', family); }
    if(genus){ u.searchParams.set('am_genus', genus); }
    if(species){ u.searchParams.set('am_species', species); }

    // Marker used by future refinements and harmless for current archive pages.
    if(search || division || family || genus || species){
      u.searchParams.set('am_restore', '1');
    }

    return u.toString();
  }

  function addReturnToLinks(root){
    if(!pageLooksLikeAMListing()){ return; }

    var current = buildReturnURL();
    var scope = root && root.querySelectorAll ? root : document;

    scope.querySelectorAll('a[href]').forEach(function(a){
      if(!isInternalAMFungoLink(a)){ return; }
      try {
        var u = new URL(a.href, window.location.href);
        // Always refresh am_return, because search/filter state can change after AJAX.
        u.searchParams.set('am_return', current);
        a.href = u.toString();
      } catch(e) {}
    });
  }

  function scheduleAddReturn(root){
    addReturnToLinks(root || document);
    window.setTimeout(function(){ addReturnToLinks(root || document); }, 150);
    window.setTimeout(function(){ addReturnToLinks(root || document); }, 600);
  }

  ready(function(){
    scheduleAddReturn(document);
    window.setTimeout(function(){ scheduleAddReturn(document); }, 1600);

    document.addEventListener('change', function(e){
      if(e.target && e.target.matches && e.target.matches('input, select')){
        scheduleAddReturn(document);
      }
    });

    document.addEventListener('input', function(e){
      if(e.target && e.target.matches && e.target.matches('input[type="search"], input[type="text"]')){
        window.setTimeout(function(){ scheduleAddReturn(document); }, 250);
      }
    });

    document.addEventListener('am:archive-updated', function(e){
      scheduleAddReturn(e && e.target ? e.target : document);
    });

    document.addEventListener('am:search-updated', function(e){
      scheduleAddReturn(e && e.target ? e.target : document);
    });

    var observed = document.querySelector('.am-archive, .am-search-page, .am-search-results, .am-archive-tree, .am-archive__results, [data-am-archive], [data-am-search]');
    if(observed && window.MutationObserver){
      var mo = new MutationObserver(function(mutations){
        var shouldRun = mutations.some(function(m){
          return m.addedNodes && m.addedNodes.length;
        });
        if(shouldRun){
          scheduleAddReturn(observed);
        }
      });
      mo.observe(observed, { childList: true, subtree: true });
    }
  });
})();


/* Guided entry form. All fields stay in the original form and retain their values. */
(function () {
  function initWizard() {
    var form = document.querySelector('#am-main-form.am-wizard');
    if (!form) return;

    var panels = Array.from(form.querySelectorAll('[data-am-step]'));
    var navigation = form.querySelector('.am-wizard__nav');
    var navButtons = Array.from(navigation.querySelectorAll('button'));
    var back = form.querySelector('[data-am-back]');
    var next = form.querySelector('[data-am-next]');
    var submit = form.querySelector('.am-submit');
    var reviewButton = form.querySelector('#am-save-review-btn');
    var config = JSON.parse(form.querySelector('[data-am-wizard-config]').textContent);
    var titlePreview = form.querySelector('#am-generated-title-preview');
    var emptyTitle = titlePreview.textContent;
    var reviewTitle = form.querySelector('[data-am-final-title]');
    var progress = form.querySelector('[data-am-progress]');
    var progressLabel = form.querySelector('#am-wizard-progress-label');
    var active = 0;

    function field(id) {
      return form.querySelector('#' + id);
    }

    function value(id) {
      var input = field(id);
      return input ? input.value.trim() : '';
    }

    function selected(id) {
      var input = field(id);
      return input && input.value && input.selectedIndex >= 0
        ? input.options[input.selectedIndex].text.trim() : '';
    }

    function descriptionText() {
      var editor = window.tinymce && window.tinymce.get('am_content');
      if (editor && editor.initialized && !editor.isHidden()) {
        return editor.getContent({format: 'text'}).trim();
      }
      // wp_editor can populate the textarea with HTML on a validation redirect.
      var template = document.createElement('template');
      template.innerHTML = value('am_content');
      return template.content.textContent.trim();
    }

    function renderTitle(element, name, suffix) {
      element.replaceChildren();
      if (!name) {
        element.textContent = emptyTitle;
        return;
      }
      var italic = document.createElement('i');
      italic.textContent = name;
      element.append(italic, suffix ? ' ' + suffix : '');
    }

    function updateSummary() {
      var genus = selected('am_genus');
      var species = selected('am_species');
      var name = genus && species ? genus + ' ' + species : '';
      var suffix = [selected('am_infra'), selected('am_author')].filter(Boolean).join(' ');
      renderTitle(titlePreview, name, suffix);
      renderTitle(reviewTitle.querySelector('[data-am-title]'), name, suffix);
      reviewTitle.hidden = !name;

      var checks = {
        classification: Boolean(selected('am_division') && selected('am_family') && name),
        description: Array.from(descriptionText()).length >= 20,
        featured: Number(value('am_featured_id')) > 0,
        gallery: value('am_gallery_ids').split(',').some(function (id) { return Number(id) > 0; }),
        pdf: Number(value('am_pdf_id')) > 0
      };
      var count = 0;
      form.querySelectorAll('[data-am-check]').forEach(function (item) {
        var key = item.dataset.amCheck;
        var optional = item.hasAttribute('data-am-optional');
        var complete = checks[key];
        if (complete && !optional) count++;
        item.classList.toggle('is-ok', complete);
        item.classList.toggle('is-optional-ok', optional && !complete);
        item.querySelector('.am-submit-checklist__status').textContent = complete ? '✓' : (optional ? '–' : '○');
        item.querySelector('[data-am-check-state]').textContent = ' – ' + (complete ? config.complete : (optional ? config.optional : config.missing));
      });
      progress.value = count;
      var message = config.progress.replace('%1$d', String(count)).replace('%2$d', String(progress.max));
      if (progressLabel.textContent !== message) progressLabel.textContent = message;
    }

    function showStep(index, moveFocus) {
      active = index;
      var last = index === panels.length - 1;
      panels.forEach(function (panel, position) {
        panel.hidden = position !== index;
        if (position === index) navButtons[position].setAttribute('aria-current', 'step');
        else navButtons[position].removeAttribute('aria-current');
      });
      back.hidden = index === 0;
      next.hidden = last;
      next.textContent = config.next[index] || '';
      submit.hidden = !last;
      if (reviewButton) reviewButton.hidden = !last;
      updateSummary();
      if (moveFocus) panels[index].querySelector('h3').focus();
    }

    form.addEventListener('click', function (event) {
      var control = event.target.closest('[data-am-go], [data-am-back], [data-am-next]');
      if (!control) return;
      event.preventDefault();
      var index = control.hasAttribute('data-am-next') ? active + 1
        : control.hasAttribute('data-am-back') ? active - 1
          : panels.findIndex(function (panel) { return panel.dataset.amStep === control.dataset.amGo; });
      if (index >= 0 && index < panels.length) showStep(index, true);
    });

    form.addEventListener('submit', function (event) {
      if (active !== panels.length - 1) {
        event.preventDefault();
        showStep(active + 1, true);
        return;
      }
      if (window.tinymce) window.tinymce.triggerSave();
    });

    form.addEventListener('input', updateSummary);
    // Taxonomy controls also emit jQuery change events after async population.
    jQuery(form).on('change', 'select, input, textarea', function () {
      window.requestAnimationFrame(updateSummary);
    });
    var observer = new MutationObserver(updateSummary);
    ['am_featured_id', 'am_gallery_ids', 'am_pdf_id'].forEach(function (id) {
      observer.observe(field(id), {attributes: true, attributeFilter: ['value']});
    });
    function watchEditor(editor) {
      if (editor && editor.id === 'am_content') {
        editor.on('init input change SetContent Undo Redo', updateSummary);
      }
    }
    if (window.tinymce) {
      watchEditor(window.tinymce.get('am_content'));
      window.tinymce.on('AddEditor', function (event) { watchEditor(event.editor); });
    }

    var errors = {
      missing_title: 0, missing_classification: 0, missing_infra: 0, missing_author: 0,
      missing_content: 1, macro_too_short: 1, missing_macro_micro: 1,
      missing_featured: 2, missing_gallery: 2, insert_failed: 3, update_failed: 3
    };
    var initialStep = Object.prototype.hasOwnProperty.call(errors, config.error) ? errors[config.error] : 0;
    showStep(initialStep, false);
    navigation.hidden = false;
    form.querySelector('[data-am-progress-box]').hidden = false;
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initWizard);
  else initWizard();
})();
