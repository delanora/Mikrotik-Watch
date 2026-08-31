<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Mikrotik Watch - Serviço de Transição de Status
 *
 * Centraliza toda a lógica de transição de estado online/offline/warning
 * com debounce (confirmação por falhas consecutivas).
 *
 * Funciona tanto para mikrotiks (online/offline) quanto para netwatch_hosts (up/down).
 *
 * Máquina de estados:
 *   online/up → (1 falha) → online/up (sem mudança)
 *   online/up → (2 falhas) → warning
 *   online/up → (3+ falhas) → offline/down (evento criado com started_at = primeira falha)
 *   warning → (1 falha a mais) → offline/down (se >= threshold)
 *   warning → (sucesso) → online/up (imediato, fecha evento se houver)
 *   offline/down → (sucesso) → online/up (imediato, fecha evento)
 */
class StatusTransition
{
    /**
     * Avalia uma verificação e atualiza status, contadores e eventos conforme necessário.
     *
     * @param PDO    $db                Conexão PDO ativa
     * @param string $table             Tabela alvo ('mikrotiks' ou 'netwatch_hosts')
     * @param string $eventsTable       Tabela de eventos ('mikrotik_events' ou 'netwatch_events')
     * @param int    $entityId          ID da entidade (coluna 'id')
     * @param bool   $checkSucceeded    Se a verificação teve sucesso (true) ou falhou (false)
     * @param string $onlineValue       Valor do status online ('online' ou 'up')
     * @param string $offlineValue      Valor do status offline ('offline' ou 'down')
     * @param int    $failureThreshold  Número de falhas consecutivas para confirmar offline
     */
    public static function evaluate(
        PDO $db,
        string $table,
        string $eventsTable,
        int $entityId,
        bool $checkSucceeded,
        string $onlineValue,
        string $offlineValue,
        int $failureThreshold,
    ): void {
        if ($checkSucceeded) {
            self::handleSuccess($db, $table, $eventsTable, $entityId, $onlineValue, $offlineValue);
        } else {
            self::handleFailure($db, $table, $eventsTable, $entityId, $onlineValue, $offlineValue, $failureThreshold);
        }
    }

    /**
     * Lógica quando a verificação teve SUCESSO.
     *
     * - Sempre atualiza last_checked_at
     * - Se havia falhas em sequência: reseta contadores
     * - Se não estava online: RECUPERAÇÃO imediata (fecha evento, volta para online)
     * - Se já estava online: nada além de last_checked_at
     */
    private static function handleSuccess(
        PDO $db,
        string $table,
        string $eventsTable,
        int $entityId,
        string $onlineValue,
        string $offlineValue,
    ): void {
        // Buscar estado atual
        $stmt = $db->prepare("
            SELECT current_status, consecutive_failures
            FROM {$table}
            WHERE id = :id
        ");
        $stmt->execute([':id' => $entityId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return;
        }

        $currentStatus = $row['current_status'];
        $consecutiveFailures = (int) $row['consecutive_failures'];

        // Se já está online e não havia falhas, apenas atualizar last_checked_at
        if ($currentStatus === $onlineValue && $consecutiveFailures === 0) {
            $stmt = $db->prepare("
                UPDATE {$table}
                SET last_checked_at = now()
                WHERE id = :id
            ");
            $stmt->execute([':id' => $entityId]);
            return;
        }

        // Resetar contadores de falha
        $params = [
            ':id'             => $entityId,
            ':online_value'   => $onlineValue,
            ':consec_failures' => $consecutiveFailures,
        ];

        // Se não estava online (era warning, offline, ou unknown) → RECUPERAÇÃO
        if ($currentStatus !== $onlineValue) {
            // Fechar evento aberto de offline, se houver
            $stmt = $db->prepare("
                UPDATE {$eventsTable}
                SET ended_at = now(),
                    duration_seconds = EXTRACT(EPOCH FROM (now() - started_at))::INTEGER
                WHERE {$eventsTable}.{$table}_id = :entity_id
                  AND {$eventsTable}.status = :offline_value
                  AND ended_at IS NULL
            ");
            $stmt->execute([
                ':entity_id'    => $entityId,
                ':offline_value' => $offlineValue,
            ]);

            // Voltar para online imediatamente
            $stmt = $db->prepare("
                UPDATE {$table}
                SET current_status = :online_value,
                    status_since = now(),
                    last_checked_at = now(),
                    consecutive_failures = 0,
                    first_failure_at = NULL
                WHERE id = :id
            ");
            $stmt->execute([
                ':online_value' => $onlineValue,
                ':id'           => $entityId,
            ]);

            return;
        }

        // Já estava online mas tinha falhas pendentes → apenas resetar contadores
        $stmt = $db->prepare("
            UPDATE {$table}
            SET last_checked_at = now(),
                consecutive_failures = 0,
                first_failure_at = NULL
            WHERE id = :id
        ");
        $stmt->execute([':id' => $entityId]);
    }

    /**
     * Lógica quando a verificação FALHOU.
     *
     * - Sempre atualiza last_checked_at
     * - Incrementa consecutive_failures
     * - Se é a primeira falha: registra first_failure_at
     * - consecutive_failures == 1: NÃO muda status (continua online)
     * - consecutive_failures == 2: muda para warning
     * - consecutive_failures >= threshold: muda para offline, cria evento
     * - consecutive_failures > threshold: idempotente (não cria evento duplicado)
     */
    private static function handleFailure(
        PDO $db,
        string $table,
        string $eventsTable,
        int $entityId,
        string $onlineValue,
        string $offlineValue,
        int $failureThreshold,
    ): void {
        // Buscar estado atual
        $stmt = $db->prepare("
            SELECT current_status, consecutive_failures, first_failure_at, status_since
            FROM {$table}
            WHERE id = :id
        ");
        $stmt->execute([':id' => $entityId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return;
        }

        $currentStatus = $row['current_status'];
        $oldFailures = (int) $row['consecutive_failures'];
        $firstFailureAt = $row['first_failure_at'];
        $newFailures = $oldFailures + 1;

        // Se já está em offline/down e já passou do threshold, apenas atualizar last_checked_at
        // (idempotência — não duplicar evento)
        if ($currentStatus === $offlineValue && $newFailures > $failureThreshold) {
            $stmt = $db->prepare("
                UPDATE {$table}
                SET last_checked_at = now(),
                    consecutive_failures = :failures
                WHERE id = :id
            ");
            $stmt->execute([
                ':failures' => $newFailures,
                ':id'       => $entityId,
            ]);
            return;
        }

        // Registrar first_failure_at se é a primeira falha
        if ($newFailures === 1) {
            $firstFailureAt = date('Y-m-d H:i:s');
        }

        // Determinar novo status
        $newStatus = $currentStatus;
        $statusChanged = false;

        if ($newFailures === 1) {
            // 1ª falha: não muda status, continua como estava
            $newStatus = $currentStatus;
        } elseif ($newFailures === 2) {
            // 2ª falha: muda para warning (se ainda não é warning)
            if ($currentStatus !== 'warning') {
                $newStatus = 'warning';
                $statusChanged = true;
            }
        } elseif ($newFailures >= $failureThreshold) {
            // Threshold atingido: muda para offline
            if ($currentStatus !== $offlineValue) {
                $newStatus = $offlineValue;
                $statusChanged = true;
            }
        }

        // Atualizar entidade
        if ($statusChanged && $newStatus === 'warning') {
            // Transição para warning
            $stmt = $db->prepare("
                UPDATE {$table}
                SET current_status = 'warning',
                    status_since = :first_failure_at,
                    last_checked_at = now(),
                    consecutive_failures = :failures,
                    first_failure_at = :first_failure_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':first_failure_at' => $firstFailureAt,
                ':failures'         => $newFailures,
                ':id'               => $entityId,
            ]);
        } elseif ($statusChanged && $newStatus === $offlineValue) {
            // Transição para offline — criar evento com started_at = first_failure_at
            $statusSince = $firstFailureAt ?? date('Y-m-d H:i:s');

            $stmt = $db->prepare("
                UPDATE {$table}
                SET current_status = :offline_value,
                    status_since = :status_since,
                    last_checked_at = now(),
                    consecutive_failures = :failures,
                    first_failure_at = :first_failure_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':offline_value'    => $offlineValue,
                ':status_since'     => $statusSince,
                ':failures'         => $newFailures,
                ':first_failure_at' => $firstFailureAt,
                ':id'               => $entityId,
            ]);

            // Criar evento de offline (verificar se já não existe um aberto)
            $entityIdColumn = ($table === 'mikrotiks') ? 'mikrotik_id' : 'netwatch_host_id';

            $stmt = $db->prepare("
                SELECT COUNT(*) FROM {$eventsTable}
                WHERE {$entityIdColumn} = :entity_id
                  AND status = :offline_value
                  AND ended_at IS NULL
            ");
            $stmt->execute([
                ':entity_id'     => $entityId,
                ':offline_value' => $offlineValue,
            ]);
            $existingOpen = (int) $stmt->fetchColumn();

            if ($existingOpen === 0) {
                $stmt = $db->prepare("
                    INSERT INTO {$eventsTable} ({$entityIdColumn}, status, started_at)
                    VALUES (:entity_id, :offline_value, :started_at)
                ");
                $stmt->execute([
                    ':entity_id'     => $entityId,
                    ':offline_value' => $offlineValue,
                    ':started_at'    => $firstFailureAt ?? date('Y-m-d H:i:s'),
                ]);
            }
        } else {
            // Sem mudança de status (1ª falha, ou já em warning e ainda não atingiu threshold)
            $stmt = $db->prepare("
                UPDATE {$table}
                SET last_checked_at = now(),
                    consecutive_failures = :failures,
                    first_failure_at = :first_failure_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':failures'         => $newFailures,
                ':first_failure_at' => $firstFailureAt,
                ':id'               => $entityId,
            ]);
        }
    }
}
