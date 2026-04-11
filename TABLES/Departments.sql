USE [TradewellDatabase]
GO

/****** Object:  Table [dbo].[Departments]    Script Date: 4/10/2026 2:57:25 PM ******/
SET ANSI_NULLS ON
GO

SET QUOTED_IDENTIFIER ON
GO

CREATE TABLE [dbo].[Departments](
	[DepartmentID] [int] IDENTITY(1,1) NOT NULL,
	[DepartmentCode] [nvarchar](50) NULL,
	[Department] [nvarchar](50) NULL,
	[DepartmentName] [nvarchar](50) NULL,
	[Code] [nchar](1) NULL,
	[Status] [bit] NULL,
	[Public_Display] [bit] NULL,
	[Marker] [nvarchar](50) NULL,
	[ColorCode] [nvarchar](20) NULL,
 CONSTRAINT [PK_Departments_1] PRIMARY KEY CLUSTERED 
(
	[DepartmentID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO


