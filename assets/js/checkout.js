var bridge_io_banks = [];
var bridge_io_search_keyword = '';

jQuery(document).ready($ => {
	if (BRIDGE_IO.pay_for_order === '1') {
		$('#payment_method_bridgeapi-io').click(bridge_io_init)
	} else {
		$(document.body).on('updated_checkout', bridge_io_init)
	}

	$( window ).resize(function() {
		brigdeWidth()
	});
	$( window ).load(function() {
		brigdeWidth()
	});
})

const bridge_io_init = () => {
	bridge_io_get_banks(bridge_io_show_banks)

	var t;
	jQuery(document).on('keyup', '#bridge-io-search-input', (e) => {
		bridge_io_search_keyword = e.target.value.trim().toLowerCase()

		if (t) {
			clearTimeout(t);
		}
		t = setTimeout(bridge_io_show_banks, 500);
	});
}

const bridge_io_show_banks = () => {
	let container = jQuery('#bridgeapi-io-banks')
	container.empty()
	bridge_io_banks.forEach(bank => {
		if (bridge_io_search_keyword && bank.name.toLowerCase().search(bridge_io_search_keyword) === -1) {
			return
		}
		let bankEl = jQuery(
			`<div class="bridge-io-bank" id="bridge-bank-${bank.id}">
				<img src="${bank.logo_url}" />
				<p>${bank.name}</p>
			</div>`
		);

		bankEl.click(bridge_io_select_bank.bind(this, bank.id))
		container.append(bankEl)
	})
	brigdeWidth()
}

const bridge_io_get_banks = (callback) => {
	var spinner = jQuery('.bridge-io-spinner')
	const error = jQuery('.bridge-io-error')

	jQuery.ajax({
		url: BRIDGE_IO.ajax,
		type: 'post',
		data: {action: 'bridge-io/ajax/get_banks', __nonce: BRIDGE_IO.nonces.get_banks},
		beforeSend: () => {
			jQuery('.bridge-io-spinner').show()
			jQuery('.bridge-io-error').hide();
		},
		success: response => {
			jQuery('.bridge-io-spinner').fadeOut(() => {
				jQuery('.bridge-io-banks').fadeIn()
				brigdeWidth();
			})
			bridge_io_banks = response.data
			callback()
		},
		error: res => {
			jQuery('.bridge-io-error').html(res.responseJSON.data).show()
			jQuery('.bridge-io-spinner').hide()
		}
	})
}

const bridge_io_select_bank = (bank_id, el) => {
	jQuery('#bridge-io-bank').val(bank_id)
	jQuery('#bridgeapi-io-banks').find('.selected').removeClass('selected')
	jQuery(`#bridge-bank-${bank_id}`).addClass('selected')
}

const brigdeWidth = () => {
	var bankContainer = jQuery('.bridge-io-banks');
	if (bankContainer.width() < 400) {
		bankContainer.addClass('smallContainer');
	} else {
		bankContainer.removeClass('smallContainer');
	}
}