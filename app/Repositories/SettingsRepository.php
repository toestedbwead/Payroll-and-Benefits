<?php

class SettingsRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function get(string $key, array $default): array
    {
        $statement = $this->connection->prepare(
            'SELECT setting_value FROM app_settings WHERE setting_key = :setting_key'
        );
        $statement->execute(['setting_key' => $key]);
        $value = $statement->fetchColumn();

        if (!is_string($value)) {
            return $default;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_replace($default, $decoded) : $default;
    }

    public function save(string $key, array $value): void
    {
        $upsert = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            : 'ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value';
        $statement = $this->connection->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)
             ' . $upsert
        );
        $statement->execute([
            'setting_key' => $key,
            'setting_value' => json_encode($value, JSON_THROW_ON_ERROR),
        ]);
    }

    public function listWorkLocations(): array
    {
        $statement = $this->connection->query(
            'SELECT id, name, address, city, province, postal_code, is_primary
             FROM work_locations ORDER BY is_primary DESC, name ASC, id ASC'
        );

        return $statement->fetchAll();
    }

    public function saveWorkLocation(?int $id, array $location): void
    {
        $this->connection->beginTransaction();

        try {
            if ($location['is_primary']) {
                $this->connection->exec('UPDATE work_locations SET is_primary = 0');
            }

            if ($id === null) {
                $statement = $this->connection->prepare(
                    'INSERT INTO work_locations (name, address, city, province, postal_code, is_primary)
                     VALUES (:name, :address, :city, :province, :postal_code, :is_primary)'
                );
            } else {
                $statement = $this->connection->prepare(
                    'UPDATE work_locations
                     SET name = :name, address = :address, city = :city, province = :province,
                         postal_code = :postal_code, is_primary = :is_primary
                     WHERE id = :id'
                );
            }

            $parameters = [
                'name' => $location['name'],
                'address' => $location['address'],
                'city' => $location['city'],
                'province' => $location['province'],
                'postal_code' => $location['postal_code'],
                'is_primary' => $location['is_primary'] ? 1 : 0,
            ];
            if ($id !== null) {
                $parameters['id'] = $id;
            }
            $statement->execute($parameters);

            if ($id !== null && $statement->rowCount() === 0) {
                $exists = $this->connection->prepare('SELECT 1 FROM work_locations WHERE id = :id');
                $exists->execute(['id' => $id]);
                if ($exists->fetchColumn() === false) {
                    throw new InvalidArgumentException('That work location could not be found.');
                }
            }

            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteWorkLocation(int $id): void
    {
        $statement = $this->connection->prepare('DELETE FROM work_locations WHERE id = :id');
        $statement->execute(['id' => $id]);

        if ($statement->rowCount() === 0) {
            throw new InvalidArgumentException('That work location could not be found.');
        }
    }
}
