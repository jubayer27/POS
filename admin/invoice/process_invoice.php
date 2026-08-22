<?php
session_start(); require_once '../../config/db.php';
if (!isset($_SESSION['user_id'])) redirect('../../index.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('create_invoice.php');
verify_csrf();
try {
 $contactId=(int)($_POST['contact_id']??0);$issue=(string)($_POST['issue_date']??'');$due=(string)($_POST['due_date']??'');
 if(!$contactId||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$issue)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)||$due<$issue) throw new InvalidArgumentException('Choose a customer and valid invoice dates.');
 $itemIds=$_POST['item_id']??[];$descriptions=$_POST['description']??[];$quantities=$_POST['quantity']??[];$prices=$_POST['unit_price']??[];$rates=$_POST['tax_rate']??[];
 if(!is_array($itemIds)||count($itemIds)===0) throw new InvalidArgumentException('Add at least one line item.');
 $pdo->beginTransaction();
 $s=$pdo->prepare('SELECT currency_code FROM contacts WHERE id=? FOR UPDATE');$s->execute([$contactId]);$currency=$s->fetchColumn();if(!$currency)throw new InvalidArgumentException('Customer not found.');
 $s=$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='invoice_next_number' FOR UPDATE");$next=max(1,(int)$s->fetchColumn());$prefix=setting($pdo,'invoice_prefix','INV-');$invoiceNo=$prefix.str_pad((string)$next,5,'0',STR_PAD_LEFT);
 $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key='invoice_next_number'")->execute([(string)($next+1)]);
 $subtotal=0.0;$taxTotal=0.0;$lines=[];$revenue=[];
 $itemStmt=$pdo->prepare('SELECT name,income_account_id FROM items WHERE id=? AND is_active=1');
 foreach($itemIds as $i=>$rawId){$id=(int)$rawId;$qty=round((float)($quantities[$i]??0),3);$price=round((float)($prices[$i]??0),2);$rate=max(0,(float)($rates[$i]??0));if(!$id||$qty<=0||$price<0)continue;$itemStmt->execute([$id]);$item=$itemStmt->fetch();if(!$item)throw new InvalidArgumentException('One selected item is unavailable.');$base=round($qty*$price,2);$tax=round($base*$rate/100,2);$subtotal+=$base;$taxTotal+=$tax;$desc=trim((string)($descriptions[$i]??''))?:$item['name'];$lines[]=[$id,$desc,$qty,$price,$rate,$tax,$base+$tax];$acc=(int)$item['income_account_id'];$revenue[$acc]=($revenue[$acc]??0)+$base;}
 if(!$lines)throw new InvalidArgumentException('Add at least one valid invoice line.');$grand=round($subtotal+$taxTotal,2);
 $stmt=$pdo->prepare("INSERT INTO invoices(invoice_number,contact_id,issue_date,due_date,currency_code,subtotal,tax_total,grand_total,status,terms) VALUES(?,?,?,?,?,?,?,?,'unpaid',?)");
 $stmt->execute([$invoiceNo,$contactId,$issue,$due,$currency,$subtotal,$taxTotal,$grand,setting($pdo,'invoice_terms')]);$invoiceId=(int)$pdo->lastInsertId();
 $lineStmt=$pdo->prepare('INSERT INTO invoice_lines(invoice_id,item_id,description,quantity,unit_price,tax_rate,tax_amount,line_total) VALUES(?,?,?,?,?,?,?,?)');foreach($lines as $line)$lineStmt->execute([$invoiceId,...$line]);
 $ar=(int)$pdo->query("SELECT id FROM chart_of_accounts WHERE account_code='1200'")->fetchColumn();$journal=[['account_id'=>$ar,'contact_id'=>$contactId,'description'=>$invoiceNo,'debit'=>$grand,'credit'=>0]];foreach($revenue as $accountId=>$amount)$journal[]=['account_id'=>$accountId,'contact_id'=>$contactId,'description'=>$invoiceNo,'debit'=>0,'credit'=>$amount];if($taxTotal>0){$taxAcc=(int)$pdo->query("SELECT id FROM chart_of_accounts WHERE account_code='2100'")->fetchColumn();$journal[]=['account_id'=>$taxAcc,'contact_id'=>$contactId,'description'=>$invoiceNo,'debit'=>0,'credit'=>$taxTotal];}
 $journalId=post_journal($pdo,$issue,$invoiceNo,'Customer invoice '.$invoiceNo,$journal,'invoice',$invoiceId);$pdo->prepare('UPDATE invoices SET journal_entry_id=? WHERE id=?')->execute([$journalId,$invoiceId]);
 $pdo->commit();redirect('view_invoice.php?id='.$invoiceId.'&created=1');
} catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(422);echo '<h2>Invoice could not be saved</h2><p>'.h($e->getMessage()).'</p><p><a href="create_invoice.php">Return to invoice</a></p>';}
