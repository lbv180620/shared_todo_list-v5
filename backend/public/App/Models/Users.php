<?php

declare(strict_types=1);

namespace App\Models;

use App\Config\Config;
use App\Utils\Common;

/**
 * ユーザーテーブルクラス
 * ユーザテーブルのCRUD処理
 */
class Users
{
    /** @var \PDO $pdo PDOクラスインスタンス*/
    private \PDO $pdo;

    /**
     * コンストラクタ
     * @param \PDO $pdo PDOクラスインスタンス
     */
    public function __construct(\PDO $pdo)
    {
        // 引数に指定されたPDOクラスのインスタンスをプロパティに代入
        // クラスのインスタンスは別の変数に代入されても同じものとして扱われる(複製されるわけではない)
        $this->pdo = $pdo;
    }

    /**
     * ユーザを新規登録する
     * @method addUser
     * @param array $post
     * @return bool
     */
    public function addUser(array $post): bool
    {
        [
            'user_name' => $user_name,
            'family_name' => $family_name,
            'first_name' => $first_name,
            'email' => $email,
            'password' => $password,
        ] = $post;

        // 同じメールアドレスのユーザーがいないか調べる
        if (!empty($this->findUserByEmail($email))) {
            // すでに同じメールアドレスをもつユーザがいる場合、falseを返す
            return false;
        }

        // パスワードをハッシュ化する
        $password = password_hash($password, PASSWORD_DEFAULT);

        // ユーザ登録情報をDBにインサートする
        $sql = "INSERT INTO users (
                user_name,
                email,
                password,
                family_name,
                first_name
                ) VALUES (
                :user_name,
                :email,
                :password,
                :family_name,
                :first_name)";

        // データ変更ありなので、トランザクション処理
        try {
            Common::beginTransaction($this->pdo);

            $stmt = $this->pdo->prepare($sql);

            $stmt->bindValue(':user_name', $user_name, \PDO::PARAM_STR);
            $stmt->bindValue(':email', $email, \PDO::PARAM_STR);
            $stmt->bindValue(':password', $password, \PDO::PARAM_STR);
            $stmt->bindValue(':family_name', $family_name, \PDO::PARAM_STR);
            $stmt->bindValue(':first_name', $first_name, \PDO::PARAM_STR);

            if ($stmt->execute()) {
                return Common::commit($this->pdo);
            }
            return false;
        } catch (\PDOException $e) {
            Common::rollBack($this->pdo);
            return false;
        } catch (\Exception $e) {
            Logger::errorLog($e->getMessage(), ['file' => __FILE__, 'line' => __LINE__]);
            return false;
        }
    }

    /**
     * 同一のメールアドレスのユーザーを探す
     * @method findUserByEmail
     * @param string $email
     * @return array ユーザーの連想配列
     */
    private function findUserByEmail(string $email): array
    {
        // usersテーブルから同一のメールアドレスのユーザーを取得するクエリ
        $sql = "SELECT * FROM users WHERE email = :email";

        $stmt = $this->pdo->prepare($sql);

        $stmt->bindValue(':email', $email, \PDO::PARAM_STR);

        $stmt->execute();

        // 該当するユーザーが1名でもいたらダメなので、fetchAllではなくfetchで十分
        $rec = $stmt->fetch();

        // fetchに失敗した場合、戻り値がfalseなので、空の配列を返すように修正
        if (empty($rec)) {
            return [];
        }

        return $rec;
    }

    /**
     * メールアドレスとパスワードが一致するユーザーを取得する
     *
     * @param string $email
     * @param string $password
     * @return array $user ユーザーの連想配列
     */
    private function getUser(?string $email, ?string $password)
    {
        // 同一のメールアドレスのユーザーを探す
        $user = $this->findUserByEmail($email);
        // 同一のメールアドレスのユーザーが無かったら、空の配列を返す
        if (empty($user)) {
            return [];
        }

        // パスワードの照合
        if (password_verify($password, $user['password'])) {
            // 照合できたら、ユーザ情報を返す
            return $user;
        }

        // 照合できなかったら、空の配列を返す
        return [];
    }

    /**
     * アカウントロック確認後ログイン
     *
     * @param array $post
     * @return bool $result
     */
    public function loginAfterAccountRockConfirmation(?array $post)
    {
        $result = false;

        $email = $post['email'];

        // 同一のメールアドレスのユーザーを探す
        $user = $this->findUserByEmail($email);
        // 同一のメールアドレスのユーザーがいる場合
        if (!empty($user)) {

            // アカウントロックされているユーザはログインできない
            if (self::isAccountLocked($user)) {
                $_SESSION['err']['acount_locked'] = Config::MSG_ACOUNT_LOCKED_ERROR;
                return $result;
            }

            // アカウントがロックされていなければ、ログイン処理
            if ($this->login($post)) {
                // エラーカウントを0にリセット
                if (!$this->resetErrorCount($user)) {
                    return $result;
                }
                $result = true;
                return $result;
            }

            // ログインに失敗したらエラーカウントを1増やす
            if (!$this->addErrorCount($user)) {
                return $result;
            }

            // エラーカウントが6以上の場合はアカウントをロックする
            // アカウントがロックされたら、falseを返す
            if ($this->lockAccount($user)) {
                $_SESSION['err']['acount_locked'] = Config::MSG_MAKE_ACOUNT_LOCKED;
                return $result;
            }
        }

        // 一致するemailのユーザがいない場合
        return $result;
    }


    /**
     * ログイン処理
     * is_deleted=1で論理削除されたユーザはログインできない
     *
     * @param array $post
     * @return bool $result
     */
    private function login(?array $post)
    {
        $result = false;

        ['email' => $email, 'password' => $password] = $post;

        // メールアドレスとパスワードが一致するユーザーを取得する
        $user = $this->getUser($email, $password);

        // 論理削除されているユーザはログインできない
        if (!empty($user) && $user['is_deleted'] === 0) {
            // セッションにユーザ情報を登録
            $_SESSION['login'] = $user;
            $result = true;
            return $result;
        }

        // メールアドレスとパスワードが一致するユーザを取得できなかった場合、空の配列を返す
        return $result;
    }

    /**
     * ログアウト処理
     *
     * @return bool
     */
    public static function logout(): bool
    {
        // ログインユーザー情報を削除して、ログアウト処理とする
        unset($_SESSION['login']);

        // 念のためにセッションに保存した他の情報も削除する
        unset($_SESSION['fill']);
        unset($_SESSION['err']);
        unset($_SESSION['success']);

        // さらに念のために全消し
        $_SESSION = array();
        return session_destroy();
    }

    /**
     * すべてのユーザ情報を全件取得
     * 論理削除されているユーザも表示する
     *
     * @return array ユーザのレコードの配列
     */
    public function getUserAll()
    {
        $sql = "SELECT id, user_name, password, family_name, first_name, is_admin, is_deleted
                FROM users
                ORDER BY id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * 論理削除されていない指定IDのユーザが存在するかどうか調べる
     *
     * @param int $id ユーザID
     * @return bool ユーザが存在するとき：true、ユーザが存在しないとき：false
     */
    public function isExistsUser($id)
    {
        // $idが数字でなかったら、falseを返却
        if (!is_numeric($id)) {
            return false;
        }

        // $idが0以下はありえないので、falseを返却
        if ($id <= 0) {
            return false;
        }

        // 退会したユーザも名前は表示する
        // $sql = "SELECT COUNT(id) AS num FROM users WHERE is_deleted = 0";
        $sql = "SELECT COUNT(id) AS num FROM users";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $ret = $stmt->fetch();

        // レコードの数が0だったらfalseを返却
        if ($ret['num'] == 0) {
            return false;
        }

        return true;
    }

    /**
     * 指定したIDに一致したユーザのレコードを1件取得
     *
     * todo_itemsテーブルのclient_idから、 作成者名を取得する
     * staff_idから担当者のレコードを取得
     *
     * @param int $id 作成者ID
     * @return array 作成者のレコード
     */
    public function getUserById(int $id)
    {
        if (!is_numeric($id)) {
            return false;
        }

        if ($id <= 0) {
            return false;
        }

        $sql = "SELECT * FROM users
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * 指定IDの1件のユーザを論理削除します。
     * usersテーブルのis_deletedフラグを1に更新する
     *
     * @param int $id ユーザID
     * @return bool $result 成功した場合:true、失敗した場合:false
     */
    public function deleteUserById(int $id): bool
    {
        if (!is_numeric($id)) {
            return false;
        }

        if ($id <= 0) {
            return false;
        }

        $sql = "UPDATE users SET
                is_deleted = 1
                WHERE id = :id";

        try {
            Common::beginTransaction($this->pdo);

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);

            if ($stmt->execute()) {
                return Common::commit($this->pdo);
            }
            return false;
        } catch (\PDOException $e) {
            Common::rollBack($this->pdo);
            return false;
        } catch (\Exception $e) {
            Logger::errorLog($e->getMessage(), ['file' => __FILE__, 'line' => __LINE__]);
            return false;
        }
    }

    /**
     * アカウントがロックされているかどうか？
     *
     * @param array $user_row
     * @return bool $result
     */
    private static function isAccountLocked(array $user_row): bool
    {
        $result = false;

        $locked_flg = $user_row['locked_flg'];
        if ($locked_flg === 1) {
            $result = true;
            return $result;
        }
        return $result;
    }

    /**
     * エラーカウントをリセットする
     *
     * @param array $user_row
     * @return bool $result
     */
    private function resetErrorCount(array $user_row): bool
    {
        // エラーカウントが0でない場合
        if ($user_row['error_count'] > 0) {
            $sql = "UPDATE users SET
                    error_count = 0
                    WHERE id = :id";

            try {
                Common::beginTransaction($this->pdo);

                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':id', $user_row['id'], \PDO::PARAM_INT);

                if ($stmt->execute()) {
                    return Common::commit($this->pdo);
                }
                return false;
            } catch (\PDOException $e) {
                Common::rollBack($this->pdo);
                return false;
            } catch (\Exception $e) {
                Logger::errorLog($e->getMessage(), ['file' => __FILE__, 'line' => __LINE__]);
                return false;
            }
        }
        return true;
    }

    /**
     * エラーカウントを1増やす
     *
     * @param array $user_row
     * @return bool $result
     */
    private function addErrorCount(array $user_row): bool
    {
        $error_count = $user_row['error_count'] + 1;

        $sql = "UPDATE users SET
                    error_count = :error_count
                    WHERE id = :id";

        try {
            Common::beginTransaction($this->pdo);

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':error_count', $error_count, \PDO::PARAM_INT);
            $stmt->bindValue(':id', $user_row['id'], \PDO::PARAM_INT);

            if ($stmt->execute()) {
                return Common::commit($this->pdo);
            }
            return false;
        } catch (\PDOException $e) {
            Common::rollBack($this->pdo);
            return false;
        } catch (\Exception $e) {
            Logger::errorLog($e->getMessage(), ['file' => __FILE__, 'line' => __LINE__]);
            return false;
        }
    }

    /**
     * アカウントをロックする
     *
     * @param array $user_row
     * @return bool $result
     */
    private function lockAccount(array $user_row): bool
    {
        if ($user_row['error_count'] >= Config::ACCOUNT_ROCK_THRESHOLD) {
            $sql = "UPDATE users SET
                    locked_flg = 1
                    WHERE id = :id";
            try {
                Common::beginTransaction($this->pdo);

                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':id', $user_row['id'], \PDO::PARAM_INT);

                if ($stmt->execute()) {
                    return Common::commit($this->pdo);
                }
                return false;
            } catch (\PDOException $e) {
                Common::rollBack($this->pdo);
                return false;
            } catch (\Exception $e) {
                Logger::errorLog($e->getMessage(), ['file' => __FILE__, 'line' => __LINE__]);
                return false;
            }
        }
        return false;
    }
}
