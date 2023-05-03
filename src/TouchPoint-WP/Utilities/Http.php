<?php

namespace tp\TouchPointWP\Utilities;

abstract class Http {
	/* 100s - Informational */
	const CONTINUE_ = 100; // Continue is a reserved word.  Thus, adding the _.
	const SWITCHING_PROTOCOLS = 101;

	/* 200s - Success */
	const OK = 200;
	const CREATED = 201;
	const ACCEPTED = 202;
	const NON_AUTHORITATIVE = 203;
	const NO_CONTENT = 204;
	const RESET_CONTENT = 205;
	const PARTIAL_CONTENT = 206;

	/* 300s - Redirection */
	const MULTIPLE_CHOICES = 300;
	const MOVED_PERMANENTLY = 301;
	const SEE_OTHER_GET = 302;
	const NOT_MODIFIED = 303;
	const SEE_OTHER_TEMP = 307;
	const SEE_OTHER = 308;

	/* 400s - Client Error*/
	const BAD_REQUEST = 400;
	const UNAUTHORIZED = 401;
	const PAYMENT_REQUIRED = 402;
	const FORBIDDEN = 403;
	const NOT_FOUND = 404;
	const METHOD_NOT_ALLOWED = 405;
	const NOT_ACCEPTABLE = 406;
	const REQUEST_TIMEOUT = 408;
	const CONFLICT = 409;
	const GONE = 410;
	const LENGTH_REQUIRED = 411;
	const PRECONDITION_FAILED = 412;
	const REQUEST_ENTITY_TOO_LARGE = 413;
	const REQUEST_URI_TOO_LONG = 414;
	const UNSUPPORTED_MEDIA_TYPE = 415;
	const REQUESTED_RANGE_NOT_SATISFIABLE = 416;
	const EXPECTATION_FAILED = 417;
	const IM_A_TEAPOT = 418;
	const UPGRADE_REQUIRED = 426;

	/* 500s - Server Error */
	const SERVER_ERROR = 500;
	const NOT_IMPLEMENTED = 501;
	const SERVICE_UNAVAILABLE = 503;
	const VERSION_NOT_SUPPORTED = 505;
}