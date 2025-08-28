(function($){
	function notify(message, type){
		// Simple fallback; can be replaced with BuddyBoss notices
		alert(message);
	}

	function serializeForm($form){
		return $form.serialize();
	}

	function wordCount(text){
		return (text || '').replace(/<[^>]*>/g,'').trim().split(/\s+/).filter(Boolean).length;
	}

	// Star widget interactions
	$(document).on('mouseenter focus', '.bbcsr-stars-input .bbcsr-star', function(){
		$(this).prevAll('.bbcsr-star').addBack().addClass('hover');
	}).on('mouseleave blur', '.bbcsr-stars-input .bbcsr-star', function(){
		$('.bbcsr-star').removeClass('hover');
	});

	$(document).on('click', '.bbcsr-star', function(){
		var forId = $(this).attr('for');
		if(forId){
			$('#' + forId).prop('checked', true).trigger('change');
		}
	});

	$(document).on('keydown', '.bbcsr-stars-input', function(e){
		var $group = $(this);
		var current = parseInt($group.find('input[type="radio"]:checked').val() || '0', 10);
		if(e.key === 'ArrowRight' || e.key === 'ArrowUp'){
			e.preventDefault();
			var nxt = Math.min(5, current + 1);
			$('#bbcsr-star-' + nxt).prop('checked', true).trigger('change');
		}else if(e.key === 'ArrowLeft' || e.key === 'ArrowDown'){
			e.preventDefault();
			var prv = Math.max(1, current - 1);
			$('#bbcsr-star-' + prv).prop('checked', true).trigger('change');
		}
	});

	// Submit review
	$(document).on('submit', '#bbcsr-review-form', function(e){
		e.preventDefault();
		var $form = $(this);
		var rating = parseInt(($form.find('input[name="rating"]:checked').val()||'0'),10);
		if(!rating){
			notify('Please select a rating.', 'error');
			return;
		}
		var text = ($form.find('textarea[name="review_text"]').val()||'');
		if(wordCount(text) > 500){
			notify('Review exceeds 500 words.', 'error');
			return;
		}
		$form.find('button[type="submit"]').prop('disabled', true);
		$.post(BBCSR.ajaxUrl, serializeForm($form))
			.done(function(resp){
				if(resp && resp.success){
					notify(resp.data && resp.data.message ? resp.data.message : 'Success', 'success');
					if(resp.data && resp.data.html){
						$('.bbcsr-review-list').prepend(resp.data.html).hide().fadeIn(200);
					}
					if(resp.data && resp.data.summary){
						$('.bbcsr-summary-box').replaceWith(resp.data.summary);
					}
					$form.remove();
				}else{
					notify((resp && resp.data && resp.data.message) || 'Request failed', 'error');
				}
			})
			.fail(function(){
				notify('Request failed', 'error');
			})
			.always(function(){
				$form.find('button[type="submit"]').prop('disabled', false);
			});
	});

	// Delete
	$(document).on('click', '.bbcsr-delete-review', function(){
		if(!confirm('Delete this review?')) return;
		var $item = $(this).closest('.bbcsr-review-item');
		var reviewId = $item.data('review-id');
		$.post(BBCSR.ajaxUrl, { action: 'bbcsr_delete_review', review_id: reviewId, nonce: BBCSR.nonce })
			.done(function(resp){
				if(resp && resp.success){
					$item.fadeOut(150, function(){ $(this).remove(); });
				}else{
					notify((resp && resp.data && resp.data.message) || 'Delete failed', 'error');
				}
			})
			.fail(function(){ notify('Delete failed', 'error'); });
	});

	// Flag
	$(document).on('click', '.bbcsr-flag-review', function(){
		var $item = $(this).closest('.bbcsr-review-item');
		var reviewId = $item.data('review-id');
		var reason = prompt('Report reason (optional):') || '';
		$.post(BBCSR.ajaxUrl, { action: 'bbcsr_flag_review', review_id: reviewId, reason: reason, nonce: BBCSR.nonce })
			.done(function(resp){ notify((resp && resp.data && resp.data.message) || 'Reported', 'success'); })
			.fail(function(){ notify('Report failed', 'error'); });
	});

	// Load more
	$(document).on('click', '.bbcsr-load-more', function(){
		var $btn = $(this);
		var sellerId = $btn.data('seller');
		var loaded = $('.bbcsr-review-item').length;
		$btn.prop('disabled', true);
		$.post(BBCSR.ajaxUrl, { action: 'bbcsr_load_reviews', seller_id: sellerId, limit: 10, offset: loaded, nonce: BBCSR.nonce })
			.done(function(resp){
				if(resp && resp.success && resp.data && resp.data.html){
					$('.bbcsr-review-list').append(resp.data.html);
				}
			})
			.always(function(){ $btn.prop('disabled', false); });
	});
})(jQuery);

