<?php

namespace Eticsoft\Sanalpospro\Common\Models;

class PaymentModel extends Entity
{
    private float $amount;
    private string $currency;
    private float $buyerFee;
    private string $method;
    private string $merchant_reference;
    private string $return_url;
    private string $callback_url;
    public function __construct(
        ?float $amount = 0.0,
        ?string $currency = 'TRY',
        ?float $buyerFee = 0.0,
        ?string $method = '',
        ?string $merchant_reference = '',
        ?string $return_url = '',
        ?string $callback_url = ''
    ) {
        $this->amount = $amount;
        $this->currency = $currency;
        $this->buyerFee = $buyerFee;
        $this->method = $method;
        $this->merchant_reference = $merchant_reference;
        $this->return_url = $return_url;
        $this->callback_url = $callback_url;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): void
    {
        $this->amount = $amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }

    public function getBuyerFee(): float
    {
        return $this->buyerFee;
    }

    public function setBuyerFee(float $buyerFee): void
    {
        $this->buyerFee = $buyerFee;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    public function getMerchantReference(): string
    {
        return $this->merchant_reference;
    }

    public function setMerchantReference(string $merchantReference): void
    {
        $this->merchant_reference = $merchantReference;
    }


    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'buyer_fee' => $this->buyerFee,
            'method' => $this->method,
            'merchant_reference' => $this->merchant_reference,
            'return_url' => $this->return_url,
            'callback_url' => $this->callback_url
        ];
    }

    public function getReturnUrl(): string
    {
        return $this->return_url;
    }

    public function setReturnUrl(string $returnUrl): void
    {
        $this->return_url = $returnUrl;
    }

    public function getCallbackUrl(): string
    {
        return $this->callback_url;
    }

    public function setCallbackUrl(string $callbackUrl): void
    {
        $this->callback_url = $callbackUrl;
    }
}
