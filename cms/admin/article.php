<?php
// 파트 A: 설정하기
declare(strict_types = 1);  // 엄격한 타입 사용
include '../includes/database-connection.php';  // 데이터베이스 연결
include '../includes/functions.php';  // 함수
include '../includes/validate.php'; // 유효성 검사 함수

$uploads = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
$file_types  = ['image/jpeg', 'image/png', 'image/gif',]; // 허용되는 타입
$file_exts   = ['jpg', 'jpeg', 'png', 'gif',];  // 허용되는 확장자
$max_size    = 5242880; //최대 파일 크기

// PHP 코드에 필요한 변수 초기화
$id          = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);  // 아이디 가져오기 + 유효성 검사
$temp        = $_FILES['image']['tmp_name'] ?? '';  // 임시 이미지
$destination = '';  // 파일을 저장할 위치

// HTML 페이지에 필요한 변수 초기화
$article = [
  'id'          => $id,   'title'       => '',
  'summary'     => '',    'content'     => '',
  'member_id'   => 0,     'category_id' => 0,
  'image_id'    => null,  'published'   => false,
  'image_file'  => '',    'image_alt'   => '',
];  // 기사 데이터

$errors = [
  'warning' => '', 'title'     => '', 'summary'    => '', 'content'    => '',
  'author'  => '', 'category'  => '', 'image_file' => '', 'image_alt'  => '',
]; // 오류 데이터

// 아이디가 있다며느 페이지는 기사 편집 중이므로 현재 기사 데이터 가져오기
if ($id) {  // 아이디가 있다면
    $sql    ="SELECT a.id, a.title, a.summary, a.content,
                     a.category_id, a.member_id, a.image_id, a.published,
                     i.file     AS image_file,
                     i.alt      AS image_alt
                FROM article    AS a
                LEFT JOIN image AS i ON a.image_id = i.id
               WHERE a.id = :id;";  // 기사를 가져오는 SQL
    $article = pdo($pdo, $sql, [$id]) ->fetch();  // 기사 데이터 가져오기
    if (!$article) { // 기사가 없다면
        redirect('articles.php', ['failure' => 'Article not found']); // 리디렉션
    }
}

$saved_image = $article['image_file'] ? ture : false;   // 이미지가 업로드 되었는가?

//모든 회원과 카테고리 가져오기
$sql        = "SELECT id, forename, surname FROM member;"; // 모든 회원 가져오는 SQL
$author     = pdo($pdo, $sql)->fetchAll(); // 모든 회원 가져오기
$sql        = "SELECT id, name FROM category;"; // 모든 카테고리 가져오는 SQL
$categories = pdo($pdo, $sql)->fetchAll(); // 모든 카테고리 가져오기

// 파트 B: 폼 가져오고 유효성 검사하기
if ($_SERVER['REQUEST_METHOD'] == 'POST') { // 폼이 제출되었다면
    // 파일이 php.ini 또는 .htaccess에서 제한된 크기보다 더 클 때의 오류 메세지
    $errors['image_file'] = ($_FILES['image']['error'] === 1) ? 'File too big ' : '';

    //이미지가 업로드 되었다면 이미지 데이터를 가져와서 유효성 검사함
    if ($temp and $_FILES['image']['error'] === 0) { // 파일이 업로드되었다면
        $article['image_alt'] = $_POST['image_alt']; // 대체 텍스트 가져오기
        //이미지 파일 유효성 검사
        $errors['image_file'] .= in_array(mine_content_type($temp), $file_types)
          ? '' : 'Wrong file type. '; // 파일 타입 검사
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $errors['image_file'] .= in_array($ext, $file_extensions)
          ? '' : 'Wrong file extension '; // 확장자 검사
        $errors['image_file'] .= ($_FILES['image']['size'] <= $max_size)
          ? '' : 'File too big '; // 크기 검사
        $errors['image_alt'] .= (is_text(article['image_alt'], 1, 254))
          ? '' : 'File too big '; // 대체 텍스트 검사

        // 이미지 파일이 유효하다면 저장할 위치 지정
        if ($errors['image_file'] === '' and $errors['image_alt'] === '') { // 유효하다면
            $article['image_file'] = create_filename($_FILES['image']['name'], $uploads);
            $destination = $uploads . $article['image_file']; // 목적지
        }
    }

  //기사 데이터 가져오기
  $article['title']         = $_POST['title']; // 제목  
  $article['summary']         = $_POST['summary']; // 요약
  $article['content']         = $_POST['content']; // 내용
  $article['member_id']         = $_POST['member_id']; // 저자
  $article['category_id']         = $_POST['category_id']; // 카테고리
  $article['published']         = (isset($_POST['published'])
        and ($_POST['published'] == 1)) ? 1 : 0; //게시되었는가?
  //기사 데이터의 유효성 검사하고 유효하지 않으면 오류 메세지 생성
  $errors['title']      =is_text($article['title'], 1, 80)
      ? '' : 'Title must be 1-80 characters';  
  $errors['summary']      =is_text($article['title'], 1, 254)
      ? '' : 'summary must be 1-254 characters';
  $errors['content']      =is_text($article['title'], 1, 100000)
      ? '' : 'Article must be 1-100000 characters';
  $errors['member']      =is_member_id($article['title'], $author)
      ? '' : 'Please select an author';
  $errors['category']      =is_category_id($article['category_id'], $categories)
      ? '' : 'Please select a category';
  $invalid  = implode($errors);

// 파트 C: 데이터가 유효한지 확인하고, 유효하다면 데이터베이스 업데이트하기
if ($invalid) {
  $errors['warning'] = 'Please correct the errors below';
} else {
  $arguments = $article;
  try {
    $pdo->beginTransaction();
    if ($destination) {
      $imagick = new \Imagick($temp);
      $imagick ->cropThumbnailImage(1200, 700);
      $imagick ->writeImage($destination);
      $sql = "INSERT INTO image (file, alt)
              VALUES (:file, :alt);";
      pdo($pdo, $sql, [$arguments['image_file'], $arguments['image_alt'],]);
      $arguments['image_id'] = $pdo->lastInsertid();
    }
    unset($arguments['image_file'], $arguments['image_alt']);
    if ($id) {
        $sql = "UPDATE article
                  SET title = :title, summary = :summary, content = :content,
                  category_id = :category_id, member_id = :member_id,
                  image_id = :image_id, published = :published
                  WHERE od = :id;";
    } else {
      unset($arguments['id']);
      $sql = "INSERT INTO article (title, summary, content, category_id, member_id, image_id, published)
              VALUES (:title, :summary, :content, :category_id, :member_id, :image_id, :published) ;";
    }
    pdo($pdo, $sql, $arguments);
    $pdo->commit();
    redirect('articles.php', ['success' => 'Article saved']);
  } catch (PDOException $e) {
    $pdo->rollBack();
    if (file_exists($destination)) {
      unlink($destination);
    }// 예외가 PDOExeption이고 무결성 제약에 걸렸다면
    if ($e->errorinfo[1] === 1062) {
      $errors['warning'] = 'Article title already used';
    } else {
      throw $e;
    }
  }
}
}
$article['image_file'] = $saved_image ? $article['image_file'] : '';

?>
<?php include '../includes/admin-header.php'; ?>
  <form action="article.php?id=<?= $id ?>" method="POST" enctype="multipart/form-data">
    <main class="container admin" id="content">

      <h1>Edit Article</h1>
      <?php if ($errors['warning']) { ?>
        <div class="alert alert-danger"><?= $errors['warning'] ?></div>
      <?php } ?>

      <div class="admin-article">
        <section class="image">
          <?php if (!$article['image_file']) { ?>
            <label for="image">Upload image:</label>
            <div class="form-group image-placeholder">
              <input type="file" name="image" class="form-control-file" id="image"><br>
              <span class="errors"><?= $errors['image_file'] ?></span>
            </div>
            <div class="form-group">
              <label for="image_alt">Alt text: </label>
              <input type="text" name="image_alt" id="image_alt" value="" class="form-control">
              <span class="errors"><?= $errors['image_alt'] ?></span>
            </div>
          <?php } else { ?>
            <label>Image:</label>
            <img src="../uploads/<?= html_escape($article['image_file']) ?>"
                 alt="<?= html_escape($article['image_alt']) ?>">
            <p class="alt"><strong>Alt text:</strong> <?= html_escape($article['image_alt']) ?></p>
            <a href="alt-text-edit.php?id=<?= $article['id'] ?>" class="btn btn-secondary">Edit alt text</a>
            <a href="image-delete.php?id=<?= $id ?>" class="btn btn-secondary">Delete image</a><br><br>
          <?php } ?>
        </section>

        <section class="text">
          <div class="form-group">
            <label for="title">Title: </label>
            <input type="text" name="title" id="title" value="<?= html_escape($article['title']) ?>"
                   class="form-control">
            <span class="errors"><?= $errors['title'] ?></span>
          </div>
          <div class="form-group">
            <label for="summary">Summary: </label>
            <textarea name="summary" id="summary"
                      class="form-control"><?= html_escape($article['summary']) ?></textarea>
            <span class="errors"><?= $errors['summary'] ?></span>
          </div>
          <div class="form-group">
            <label for="content">Content: </label>
            <textarea name="content" id="content"
                      class="form-control"><?= html_escape($article['content']) ?></textarea>
            <span class="errors"><?= $errors['content'] ?></span>
          </div>
          <div class="form-group">
            <label for="member_id">Author: </label>
            <select name="member_id" id="member_id">
              <?php foreach ($authors as $author) { ?>
                <option value="<?= $author['id'] ?>"
                    <?= ($article['member_id'] == $author['id']) ? 'selected' : ''; ?>>
                    <?= html_escape($author['forename'] . ' ' . $author['surname']) ?></option>
              <?php } ?>
            </select>
            <span class="errors"><?= $errors['author'] ?></span>
          </div>
          <div class="form-group">
            <label for="category">Category: </label>
            <select name="category_id" id="category">
              <?php foreach ($categories as $category) { ?>
                <option value="<?= $category['id'] ?>"
                    <?= ($article['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                    <?= html_escape($category['name']) ?></option>
              <?php } ?>
            </select>
            <span class="errors"><?= $errors['category'] ?></span>
          </div>
          <div class="form-check">
            <input type="checkbox" name="published" value="1" class="form-check-input" id="published"
                <?= ($article['published'] == 1) ? 'checked' : ''; ?>>
            <label for="published" class="form-check-label">Published</label>
          </div>
          <input type="submit" name="update" value="Save" class="btn btn-primary">
        </section><!-- /.text -->
      </div><!-- /.admin-article -->
    </main>
  </form>
<?php include '../includes/admin-footer.php'; ?>