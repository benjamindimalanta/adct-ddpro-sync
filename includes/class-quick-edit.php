<?php
/**
 * Add the GTIN (WooCommerce global unique id) to the products list Quick Edit.
 *
 * WooCommerce shows the GTIN as a list column and already prints its value into
 * each row's hidden inline-data block (`#woocommerce_inline_{id} .global_unique_id`),
 * but it leaves the field out of the Quick Edit form — only the SKU is editable
 * there. This module adds the field, populates it from that existing inline data,
 * and saves it back through the product's own setter so it stays in sync with the
 * `_global_unique_id` meta the rest of the plugin reads and writes.
 *
 * @package ADCT_DDPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ADCT_DDPro_Quick_Edit {

	public function hooks(): void {
		add_action( 'woocommerce_product_quick_edit_end', array( $this, 'field' ) );
		add_action( 'woocommerce_product_quick_edit_save', array( $this, 'save' ) );
		add_action( 'admin_footer-edit.php', array( $this, 'script' ) );
	}

	/**
	 * Render the GTIN input inside the Quick Edit form, matching WooCommerce's own
	 * field markup so it inherits the panel's styling.
	 */
	public function field(): void {
		?>
		<label>
			<span class="title"><?php esc_html_e( 'GTIN', 'adct-ddpro' ); ?></span>
			<span class="input-text-wrap">
				<input type="text" name="_ddpro_gtin" class="text ddpro_gtin" value="">
			</span>
		</label>
		<br class="clear" />
		<?php
	}

	/**
	 * Persist the edited GTIN.
	 *
	 * WooCommerce has already called $product->save() by the time this action
	 * fires, so a second save() is required for the new value to stick. Nonce and
	 * capability were checked by WooCommerce's own Quick Edit handler upstream.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function save( $product ): void {
		if ( ! $product instanceof WC_Product || ! method_exists( $product, 'set_global_unique_id' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_REQUEST['_ddpro_gtin'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$new = (string) wc_clean( wp_unslash( $_REQUEST['_ddpro_gtin'] ) );

		if ( $new === (string) $product->get_global_unique_id() ) {
			return; // Unchanged — nothing to write.
		}

		$product->set_global_unique_id( $new );
		$product->save();
	}

	/**
	 * Copy the row's existing GTIN into the Quick Edit input when it opens.
	 *
	 * WooCommerce populates the fields it knows about; this fills our added one
	 * from the same hidden inline-data block. The deferred read runs after
	 * WordPress has built the edit row.
	 */
	public function script(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		?>
		<script>
		( function ( $ ) {
			$( '#the-list' ).on( 'click', '.editinline', function () {
				var id = $( this ).closest( 'tr' ).attr( 'id' );
				if ( ! id ) { return; }
				id = id.replace( 'post-', '' );
				var gtin = $.trim( $( '#woocommerce_inline_' + id + ' .global_unique_id' ).text() );
				// Defer: the Quick Edit row is built synchronously on this same click.
				setTimeout( function () {
					$( 'input[name="_ddpro_gtin"]', '#edit-' + id ).val( gtin );
				}, 0 );
			} );
		} )( jQuery );
		</script>
		<?php
	}
}
