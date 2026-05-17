<?php
/**
 * KBoard category → inquiry_category 슬러그 매핑.
 *
 * 도착 슬롯 5개: language-study / university / early-study / visa / etc.
 * 출발 텍스트는 KBoard `category1` / `category2` 컬럼 값이다.
 * 우선순위:
 *   1) category1 매핑 시도
 *   2) 실패 시 category2
 *   3) 둘 다 실패면 'etc'
 *
 * 본 룰은 운영 데이터 distinct 값 실측 후 보정해야 한다. 마이그레이션 커맨드는
 * 실행 시 distinct 값 + 미매핑 카운트를 리포트하므로, 이 파일을 수정 후 재실행하면 된다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function inquiry_board_category_rules(): array {
	return [
		// 어학연수
		'어학연수'     => 'language-study',
		'연수'         => 'language-study',
		'어학'         => 'language-study',
		'어학원'       => 'language-study',
		'language'     => 'language-study',
		// 대학/대학원
		'대학'         => 'university',
		'대학교'       => 'university',
		'대학원'       => 'university',
		'학부'         => 'university',
		'석사'         => 'university',
		'박사'         => 'university',
		'정규유학'     => 'university',
		'정규'         => 'university',
		'university'   => 'university',
		// 조기유학
		'조기유학'     => 'early-study',
		'조기'         => 'early-study',
		'초중고'       => 'early-study',
		'초등'         => 'early-study',
		'중등'         => 'early-study',
		'고등'         => 'early-study',
		// 비자
		'비자'         => 'visa',
		'비자/이민'    => 'visa',
		'이민'         => 'visa',
		'visa'         => 'visa',
		// 기타
		'기타'         => 'etc',
		'etc'          => 'etc',
	];
}

function inquiry_board_resolve_category( ?string $cat1, ?string $cat2 ): string {
	$rules = inquiry_board_category_rules();
	foreach ( [ $cat1, $cat2 ] as $raw ) {
		if ( $raw === null ) {
			continue;
		}
		$norm = trim( (string) $raw );
		if ( $norm === '' ) {
			continue;
		}
		if ( isset( $rules[ $norm ] ) ) {
			return $rules[ $norm ];
		}
		// 키워드 부분 일치 (예: "어학연수(미국)" → "어학연수")
		foreach ( $rules as $needle => $slug ) {
			if ( $needle !== '' && mb_stripos( $norm, $needle ) !== false ) {
				return $slug;
			}
		}
	}
	return 'etc';
}
