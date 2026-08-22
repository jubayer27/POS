<?php
session_start(); require_once '../../config/db.php';
if (!isset($_SESSION['user_id'])) redirect('../../index.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('create_invoice.php');
verify_csrf();
try {
 $contactId=(int)($_POST['contact_id']??0);$issue=(string)($_POST['issue_date']??'');$due=(string)($_POST['due_date']??'');$currencyCode=(string)($_POST['currency_code']??'');$templateId=(int)($_POST['invoice_template_id']??0);
 if(!$contactId||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$issue)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)||$due<$issue) throw new InvalidArgumentException('Choose a customer and valid invoice dates.');
 $itemIds=$_POST['item_id']??[];$descriptions=$_POST['description']??[];$quantities=$_POST['quantity']??[];$prices=$_POST['unit_price']??[];$rates=$_POST['tax_rate']??[];
 if(!is_array($itemIds)||count($itemIds)===0) throw new InvalidArgumentException('Add at least one line item.');
 $pdo->beginTransaction();
 $s=$pdo->prepare("SELECT c.id FROM contacts c LEFT JOIN contact_types t ON t.id=c.contact_type_id WHERE c.id=? AND COALESCE(t.base_kind,c.contact_type) IN('customer','both') FOR UPDATE");$s->execute([$contactId]);if(!$s->fetchColumn())throw new InvalidArgumentException('Customer not found or not eligible for invoicing.');
 $s=$pdo->prepare('SELECT exchange_rate FROM currencies WHERE code=?');$s->execute([$currencyCode]);$exchangeRate=(float)$s->fetchColumn();if($exchangeRate<=0)throw new InvalidArgumentException('Choose a valid invoice currency.');$s=$pdo->prepare('SELECT id FROM invoice_templates WHERE id=?');$s->execute([$templateId]);if(!$s->fetchColumn())throw new InvalidArgumentException('Choose a valid invoice template.');
 $s=$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='invoice_next_number' FOR UPDATE");$next=max(1,(int)$s->fetchColumn());$prefix=setting($pdo,'invoice_prefix','INV-');$invoiceNo=$prefix.str_pad((string)$next,5,'0',STR_PAD_LEFT);
 $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key='invoice_next_number'")->execute([(string)($next+1)]);
 $subtotal=0.0;$taxTotal=0.0;$lines=[];$revenue=[];
 $itemStmt=$pdo->prepare('SELECT name,income_account_id FROM items WHERE id=? AND is_active=1');
 foreach($itemIds as $i=>$rawId){$id=(int)$rawId;$qty=round((float)($quantities[$i]??0),3);$price=round((float)($prices[$i]??0),2);$rate=max(0,(float)($rates[$i]??0));if(!$id||$qty<=0||$price<0)continue;$itemStmt->execute([$id]);$item=$itemStmt->fetch();if(!$item)throw new InvalidArgumentException('One selected item is unavailable.');$base=round($qty*$price,2);$tax=round($base*$rate/100,2);$subtotal+=$base;$taxTotal+=$tax;$desc=trim((string)($descriptions[$i]??''))?:$item['name'];$lines[]=[$id,$desc,$qty,$price,$rate,$tax,$base+$tax];$acc=(int)$item['income_account_id'];$revenue[$acc]=($revenue[$acc]??0)+$base;}
 if(!$lines)throw new InvalidArgumentException('Add at least one valid invoice line.');$grand=round($subtotal+$taxTotal,2);
 $stmt=$pdo->prepare("INSERT INTO invoices(invoice_number,contact_id,invoice_template_id,issue_date,due_date,currency_code,exchange_rate,subtotal,tax_total,grand_total,status,notes,terms) VALUES(?,?,?,?,?,?,?,?,?,?,'unpaid',?,?)");
 $stmt->execute([$invoiceNo,$contactId,$templateId,$issue,$due,$currencyCode,$exchangeRate,$subtotal,$taxTotal,$grand,trim((string)($_POST['notes']??'')),trim((string)($_POST['terms']??setting($pdo,'invoice_terms')))]);$invoiceId=(int)$pdo->lastInsertId();
 $lineStmt=$pdo->prepare('INSERT INTO invoice_lines(invoice_id,item_id,description,quantity,unit_price,tax_rate,tax_amount,line_total) VALUES(?,?,?,?,?,?,?,?)');foreach($lines as $line)$lineStmt->execute([$invoiceId,...$line]);
 $baseGrand=round($grand/$exchangeRate,2);$baseTax=round($taxTotal/$exchangeRate,2);$ar=(int)$pdo->query("SELECT id FROM chart_of_accounts WHERE account_code='1200'")->fetchColumn();$journal=[['account_id'=>$ar,'contact_id'=>$contactId,'description'=>$invoiceNo,'debit'=>$baseGrand,'credit'=>0]];$baseRevenueTotal=0;foreach($revenue as $accountId=>$amount){$baseAmount=round($amount/$exchangeRate,2);$baseRevenueTotal+=$baseAmount;$journal[]=['account_id'=>$accountId,'contact_id'=>$contactId,'description'=>$invoiceNo,'debit'=>0,'credit'=>$baseAmount];}if($baseTax>0){$taxAcc=(int)$pdo->query("SELECT id FROM chart_of_accounts WHERE account_code='2100'")->fetchColumn();$rounding=$baseGrand-$baseRevenueTotal-$baseTax;$journal[]=['account_id'=>$taxAcc,'contact_id'=>$contactId,'description'=>$invoiceNo,'debit'=>0,'credit'=>$baseTax+$rounding];}else{$last=count($journal)-1;$journal[$last]['credit']+=($baseGrand-$baseRevenueTotal);}
 $journalId=post_journal($pdo,$issue,$invoiceNo,'Customer invoice '.$invoiceNo,$journal,'invoice',$invoiceId);$pdo->prepare('UPDATE invoices SET journal_entry_id=? WHERE id=?')->execute([$journalId,$invoiceId]);
 $pdo->commit();redirect('view_invoice.php?id='.$invoiceId.'&created=1');
} catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(422);echo '<h2>Invoice could not be saved</h2><p>'.h($e->getMessage()).'</p><p><a href="create_invoice.php">Return to invoice</a></p>';}
