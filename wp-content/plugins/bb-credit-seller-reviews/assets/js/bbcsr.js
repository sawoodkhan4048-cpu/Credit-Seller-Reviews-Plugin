(function($){
	$(document).on('submit', '#bbcsr-review-form', function(e){
		e.preventDefault();
		var $form = $(this);
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

