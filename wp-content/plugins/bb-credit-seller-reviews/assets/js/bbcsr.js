(function($){
	function setRating(value){
		var id = '#bbcsr-star-' + value;
		$(id).prop('checked', true).trigger('change');
	}

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
			setRating(Math.min(5, current + 1));
		} else if(e.key === 'ArrowLeft' || e.key === 'ArrowDown'){
			e.preventDefault();
			setRating(Math.max(1, current - 1));
		}
	});

	$(document).on('submit', '#bbcsr-review-form', function(e){
		e.preventDefault();
		var $form = $(this);
		var text = ($form.find('textarea[name="review_text"]').val() || '').replace(/<[^>]*>/g, '');
		var words = text.trim().split(/\s+/).filter(Boolean);
		if(words.length > 500){
			alert('Review exceeds 500 words.');
			return;
		}
		var data = $form.serialize();
		$form.find('button[type="submit"]').prop('disabled', true);
		$.post(BBCSR.ajaxUrl, data)
			.done(function(resp){
				alert(resp && resp.data && resp.data.message ? resp.data.message : 'Success');
				location.reload();
			})
			.fail(function(){
				alert('Request failed');
			})
			.always(function(){
				$form.find('button[type="submit"]').prop('disabled', false);
			});
	});
})(jQuery);

