<?php

namespace App\Services\Payments;

use RuntimeException;

/** Paiement en ligne impossible (fournisseur injoignable, refus, configuration) : message lisible */
class PaymentException extends RuntimeException {}
