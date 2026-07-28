<?php

namespace App\Actions\Scheduling;

use RuntimeException;

final class SchedulingOperatingEnvelopeCostEstimator
{
    /**
     * @return array{
     *     billable_seconds_proxy:float,
     *     gross_compute_usd:float,
     *     request_usd:float,
     *     gross_request_cost_usd:float,
     *     measurement_basis:'client_elapsed_proxy',
     *     free_tier_applied:false
     * }
     */
    public function estimate(float $elapsedMilliseconds, int $cpu, int $memoryGib): array
    {
        $pricing = $this->pricing();
        $quantumMilliseconds = (int) $pricing['billing_quantum_ms'];
        $billableMilliseconds = max(
            $quantumMilliseconds,
            (int) ceil(max(0.0, $elapsedMilliseconds) / $quantumMilliseconds) * $quantumMilliseconds,
        );
        $billableSeconds = $billableMilliseconds / 1_000;
        $computeCost = $billableSeconds * (
            ($cpu * (float) $pricing['cpu_per_vcpu_second_usd'])
            + ($memoryGib * (float) $pricing['memory_per_gib_second_usd'])
        );
        $requestCost = (float) $pricing['request_per_million_usd'] / 1_000_000;

        return [
            'billable_seconds_proxy' => round($billableSeconds, 3),
            'gross_compute_usd' => round($computeCost, 10),
            'request_usd' => round($requestCost, 10),
            'gross_request_cost_usd' => round($computeCost + $requestCost, 10),
            'measurement_basis' => 'client_elapsed_proxy',
            'free_tier_applied' => false,
        ];
    }

    /**
     * @return array{
     *     region:string,
     *     effective_date:string,
     *     billing_mode:'request_based',
     *     cpu_sku_id:string,
     *     memory_sku_id:string,
     *     request_sku_id:string,
     *     source_url:string,
     *     sku_source_url:string,
     *     cpu_per_vcpu_second_usd:float,
     *     memory_per_gib_second_usd:float,
     *     request_per_million_usd:float,
     *     billing_quantum_ms:int,
     *     measurement_basis:'client_elapsed_proxy',
     *     free_tier_applied:false,
     *     interpretation:string
     * }
     */
    public function assumptions(): array
    {
        $pricing = $this->pricing();

        return [
            ...$pricing,
            'measurement_basis' => 'client_elapsed_proxy',
            'free_tier_applied' => false,
            'interpretation' => 'Gross request-based Cloud Run proxy before free tier, discounts, network, logging, registry, and unrelated platform charges. Replace with billing export evidence when available.',
        ];
    }

    /**
     * @return array{
     *     region:string,
     *     effective_date:string,
     *     billing_mode:'request_based',
     *     cpu_sku_id:string,
     *     memory_sku_id:string,
     *     request_sku_id:string,
     *     source_url:string,
     *     sku_source_url:string,
     *     cpu_per_vcpu_second_usd:float,
     *     memory_per_gib_second_usd:float,
     *     request_per_million_usd:float,
     *     billing_quantum_ms:int
     * }
     */
    private function pricing(): array
    {
        $pricing = config('tala_integrations.scheduling_solver.operating_envelope.pricing');

        if (! is_array($pricing)) {
            throw new RuntimeException('TAL-96D5D Cloud Run pricing metadata is not configured.');
        }

        $required = [
            'region',
            'effective_date',
            'billing_mode',
            'cpu_sku_id',
            'memory_sku_id',
            'request_sku_id',
            'source_url',
            'sku_source_url',
            'cpu_per_vcpu_second_usd',
            'memory_per_gib_second_usd',
            'request_per_million_usd',
            'billing_quantum_ms',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $pricing) || $pricing[$key] === null || $pricing[$key] === '') {
                throw new RuntimeException("TAL-96D5D Cloud Run pricing metadata [{$key}] is missing.");
            }
        }

        if ((int) $pricing['billing_quantum_ms'] !== 100) {
            throw new RuntimeException('TAL-96D5D cost proxy requires the disclosed 100 ms Cloud Run billing quantum.');
        }

        if ($pricing['billing_mode'] !== 'request_based') {
            throw new RuntimeException('TAL-96D5D cost proxy requires Cloud Run request-based service pricing.');
        }

        return [
            'region' => (string) $pricing['region'],
            'effective_date' => (string) $pricing['effective_date'],
            'billing_mode' => 'request_based',
            'cpu_sku_id' => (string) $pricing['cpu_sku_id'],
            'memory_sku_id' => (string) $pricing['memory_sku_id'],
            'request_sku_id' => (string) $pricing['request_sku_id'],
            'source_url' => (string) $pricing['source_url'],
            'sku_source_url' => (string) $pricing['sku_source_url'],
            'cpu_per_vcpu_second_usd' => (float) $pricing['cpu_per_vcpu_second_usd'],
            'memory_per_gib_second_usd' => (float) $pricing['memory_per_gib_second_usd'],
            'request_per_million_usd' => (float) $pricing['request_per_million_usd'],
            'billing_quantum_ms' => (int) $pricing['billing_quantum_ms'],
        ];
    }
}
