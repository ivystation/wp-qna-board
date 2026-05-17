<?php
/**
 * [inquiry_form] 의 기본 view 인 목록 화면.
 * 테마에서 inquiry-list.php 로 오버라이드 가능.
 *
 * 사용 가능 변수:
 *   $atts (array): 숏코드 속성
 *     - posts_per_page (int)
 *     - show_write_button (bool)
 *     - category (string slug)
 *     - title (string)
 *     - redirect (string)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$default_ppp = function_exists( 'inquiry_board_get_posts_per_page' )
	? inquiry_board_get_posts_per_page()
	: 20;
$per_page = max( 1, (int) ( $atts['posts_per_page'] ?? $default_ppp ) );

// 정적 페이지에 박힌 쇼트코드라 ?paged 는 WP 메인 쿼리와 충돌할 수 있어
// 별도 쿼리 변수 ?inq_paged 를 사용한다.
$paged = isset( $_GET['inq_paged'] )
	? max( 1, (int) $_GET['inq_paged'] )
	: max( 1, (int) ( get_query_var( 'paged' ) ?: 1 ) );

$query_args = [
	'post_type'           => 'inquiry',
	'post_status'         => 'publish',
	'posts_per_page'      => $per_page,
	'paged'               => $paged,
	'ignore_sticky_posts' => true,
	'no_found_rows'       => false,
	'orderby'             => 'date',
	'order'               => 'DESC',
];

if ( ! empty( $atts['category'] ) ) {
	$query_args['tax_query'] = [
		[
			'taxonomy' => 'inquiry_category',
			'field'    => 'slug',
			'terms'    => sanitize_key( (string) $atts['category'] ),
		],
	];
}

$list = new WP_Query( $query_args );

$base_url  = inquiry_board_current_page_url();
$write_url = add_query_arg( 'ipv', 'write', $base_url );
?>
<section class="inquiry-board-list">
	<?php if ( ! empty( $atts['show_write_button'] ) ) : ?>
		<div class="inquiry-list-actions">
			<a class="button button-primary inquiry-write-btn" href="<?php echo esc_url( $write_url ); ?>">
				<?php esc_html_e( '글쓰기', 'wp-qna-board' ); ?>
			</a>
		</div>
	<?php endif; ?>

	<?php if ( $list->have_posts() ) : ?>
		<table class="inquiry-list">
			<thead>
				<tr>
					<th class="col-num"><?php esc_html_e( '번호', 'wp-qna-board' ); ?></th>
					<th class="col-cat"><?php esc_html_e( '카테고리', 'wp-qna-board' ); ?></th>
					<th class="col-title"><?php esc_html_e( '제목', 'wp-qna-board' ); ?></th>
					<th class="col-author"><?php esc_html_e( '작성자', 'wp-qna-board' ); ?></th>
					<th class="col-date"><?php esc_html_e( '작성일', 'wp-qna-board' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$row_no = ( $list->found_posts ?: 0 ) - ( ( $paged - 1 ) * $per_page );
			while ( $list->have_posts() ) :
				$list->the_post();
				$post_id    = (int) get_the_ID();
				$terms      = get_the_terms( $post_id, 'inquiry_category' );
				$cat_label  = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
				$author_nm     = (string) get_post_meta( $post_id, '_inquiry_author_name', true );
				$is_locked     = post_password_required( $post_id );
				$comment_count = (int) get_comments_number( $post_id );
				?>
				<tr>
					<td class="col-num"><?php echo (int) $row_no; ?></td>
					<td class="col-cat"><?php echo esc_html( $cat_label ); ?></td>
					<td class="col-title">
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
						<?php if ( $is_locked ) : ?> <span class="inq-locked" aria-label="<?php esc_attr_e( '비밀글', 'wp-qna-board' ); ?>">🔒</span><?php endif; ?>
						<?php if ( $comment_count > 0 ) : ?>
							<span class="inq-comment-count" aria-label="<?php echo esc_attr( sprintf( __( '답변 %d개', 'wp-qna-board' ), $comment_count ) ); ?>"><?php echo (int) $comment_count; ?></span>
						<?php endif; ?>
					</td>
					<td class="col-author"><?php echo esc_html( $author_nm !== '' ? $author_nm : __( '익명', 'wp-qna-board' ) ); ?></td>
					<td class="col-date"><?php echo esc_html( get_the_date() ); ?></td>
				</tr>
				<?php
				$row_no--;
			endwhile;
			?>
			</tbody>
		</table>

		<?php
		$total_pages = (int) $list->max_num_pages;
		if ( $total_pages > 1 ) :
			$pagination = paginate_links( [
				'base'      => add_query_arg( 'paged', '%#%', $base_url ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $total_pages,
				'mid_size'  => 2,
				'prev_text' => __( '« 이전', 'wp-qna-board' ),
				'next_text' => __( '다음 »', 'wp-qna-board' ),
				'type'      => 'list',
			] );
			if ( $pagination ) :
				echo '<nav class="inquiry-pagination" aria-label="' . esc_attr__( '페이지 이동', 'wp-qna-board' ) . '">' . $pagination . '</nav>';
			endif;
		endif;

		wp_reset_postdata();
		?>
	<?php else : ?>
		<p class="inquiry-empty"><?php esc_html_e( '아직 등록된 문의가 없습니다.', 'wp-qna-board' ); ?></p>
	<?php endif; ?>
</section>
