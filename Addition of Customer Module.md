Addition of Customer Module

rbac module key = customer_list

Customer list have 2 different Tables or tab which is the following

Delivery Table = fetched from the View_RemittanceCollectionSlip2
Columns (CustomerName1 as Customer, Department, Branch, Area, Salesman, Remarks as Mode of Payments)
When a user selects a particular row/data another page or another modal will show all transactions with that particular customer that can be filtered by Date, Mode of Payment etc.

USE [TradewellDatabase]
GO

SELECT [Remittance_InvID]
      ,[DocNo]
      ,[InvoiceID]
      ,[NetAmount]
      ,[CashAmount]
      ,[CheckAmount]
      ,[CreditAmount]
      ,[AdjustmentAmount]
      ,[AddLess]
      ,[Remarks]
      ,[Note]
      ,[Bank]
      ,[CheckNo]
      ,[CheckDate]
      ,[TotalPaid]
      ,[DatetimeInput]
      ,[UserID]
      ,[InvoiceNo]
      ,[Customer]
      ,[Branch]
      ,[Department]
      ,[DocDate]
      ,[Salesman]
      ,[SalesmanCode]
      ,[Area]
      ,[TotalCalls]
      ,[ManualLess]
      ,[ManualAdd]
      ,[RemarksSummary]
      ,[TotalNetAmount]
      ,[InvoiceDate]
      ,[SalesmanID]
      ,[RCheckID]
      ,[RRID]
      ,[InvoiceRemarks]
      ,[ARCreate]
  FROM [dbo].[View_RemittanceCollectionSlip2]

GO




 AR Table = fetched from the View_ARForCollectionDetails
Columns (CustomerName1 as Customer, Department, Branch, Area, Salesman, RemitRemarks as Mode of Payments)
When a user selects a particular row/data another page or another modal will show all transactions with that particular customer that can be filtered by Date, Mode of Payment etc.

USE [TradewellDatabase]
GO

SELECT [ARForCollectionID]
      ,[Department]
      ,[Branch]
      ,[SalesmanID]
      ,[Area]
      ,[Remarks]
      ,[UserID]
      ,[DateAndTimeInput]
      ,[Status]
      ,[StatusID]
      ,[Note]
      ,[Tag_DeliveryID]
      ,[Tag_EmployeeID]
      ,[ARRefNo]
      ,[ARCollectionNo]
      ,[ARDepartment]
      ,[ARArea]
      ,[Salesman]
      ,[ARRemarks]
      ,[DeliveryDate]
      ,[AreaBranch]
      ,[ForCollectionNo]
      ,[RemittanceNo]
      ,[DocNo]
      ,[CustomerName]
      ,[InvoiceNo]
      ,[InvoiceDate]
      ,[TotalAmount]
      ,[Remitted]
      ,[Deduction]
      ,[UncollectedID]
      ,[CheckAmount]
      ,[Cash]
      ,[Balance]
      ,[RemitRemarks]
      ,[Bank]
      ,[BankBranch]
      ,[CheckNo]
      ,[CheckDate]
      ,[PaidAmount]
      ,[Terms]
      ,[ARNote]
      ,[DateCollection]
      ,[InvoiceNoTag]
      ,[PayTag]
      ,[Status1]
      ,[InvoiceAmount]
      ,[CustomerName1]
      ,[InputName]
      ,[SalesmanName]
      ,[ARForCollectionDate]
      ,[RRID]
  FROM [dbo].[View_ARForCollectionDetails]

GO




